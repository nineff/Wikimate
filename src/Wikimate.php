<?php

declare(strict_types=1);

namespace NNS\Wikimate;

use WpOrg\Requests\Session;

/**
 * Wikimate is a wrapper for the MediaWiki API that aims to be very easy to use.
 *
 * @version    2.0.0
 *
 * @copyright  SPDX-License-Identifier: MIT
 */

/**
 * Provides an interface over wiki API objects such as pages and files.
 *
 * All requests to the API can throw WikimateException if the server is lagged
 * and a finite number of retries is exhausted.  By default requests are
 * retried indefinitely.  See {@see Wikimate::request()} for more information.
 *
 * @author Robert McLeod & Frans P. de Vries
 * @author Nikolai Neff
 *
 * @since   0.2  December 2010
 */
class Wikimate
{
    /**
     * The current version number (conforms to Semantic Versioning).
     *
     * @var string
     *
     * @see https://semver.org/
     */
    public const VERSION = '2.0.0';

    /**
     * Identifier for CSRF token.
     *
     * @var string
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Tokens
     */
    public const TOKEN_DEFAULT = 'csrf';

    /**
     * Identifier for Login token.
     *
     * @var string
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Tokens
     */
    public const TOKEN_LOGIN = 'login';

    /**
     * Default lag value in seconds.
     *
     * @var int
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/Manual:Maxlag_parameter
     */
    public const MAXLAG_DEFAULT = 5;

    /**
     * Base URL for API requests.
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Main_page#Endpoint
     */
    protected string $api;

    /**
     * Default headers for Requests_Session.
     */
    protected array $headers;

    /**
     * Default data for Requests_Session.
     */
    protected array $data;

    /**
     * Default options for Requests_Session.
     */
    protected array $options;

    /**
     * Username for API requests.
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Login#Method_1._login
     */
    protected string $username;

    /**
     * Password for API requests.
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Login#Method_1._login
     */
    protected string $password;

    /**
     * Session object for HTTP requests.
     *
     * @see https://requests.ryanmccue.info/
     */
    protected Session $session;

    /**
     * User agent string for Requests_Session.
     *
     * @see https://requests.ryanmccue.info/docs/usage-advanced.html#session-handling
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Etiquette#The_User-Agent_header
     */
    protected string $useragent;

    /**
     * Error array with API and Wikimate errors.
     */
    protected ?array $error = null;


    /**
     * Maximum lag in seconds to accept in requests.
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/Manual:Maxlag_parameter
     */
    protected int $maxlag = self::MAXLAG_DEFAULT;

    /**
     * Maximum number of retries for lagged requests (-1 = retry indefinitely).
     */
    protected int $maxretries = -1;

    /**
     * Stored CSRF token for API requests.
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Tokens
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Edit#Additional_notes
     */
    private ?string $csrfToken = null;

    /**
     * Whether instance is logged in or not.
     */
    protected bool $loggedIn = false;

    /**
     * Creates a new Wikimate object.
     *
     * @param string $api     Base URL for the API
     * @param array  $headers Default headers for API requests
     * @param array  $data    Default data for API requests
     * @param array  $options Default options for API requests
     *
     * @return Wikimate
     */
    public function __construct(string $api, array $headers = [], array $data = [], array $options = [])
    {
        $this->api = $api;
        $this->headers = $headers;
        $this->data = $data;
        $this->options = $options;

        $this->initRequests();
    }

    /**
     * Sets up a Requests_Session with appropriate user agent.
     *
     * @see https://requests.ryanmccue.info/docs/usage-advanced.html#session-handling
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Etiquette#The_User-Agent_header
     */
    protected function initRequests(): void
    {
        $this->useragent = 'Wikimate/'.self::VERSION.' (https://github.com/hamstar/Wikimate)';

        $this->session = new Session($this->api, $this->headers, $this->data, $this->options);
        $this->session->useragent = $this->useragent;
    }

    /**
     * Sends a GET or POST request in JSON format to the API.
     *
     * This method handles maxlag errors as advised at:
     * {@see https://www.mediawiki.org/wiki/Special:MyLanguage/Manual:Maxlag_parameter}
     * The request is sent with the current maxlag value
     * (default: 5 seconds, per MAXLAG_DEFAULT).
     * If a lag error is received, the method waits (sleeps) for the
     * recommended time (per the Retry-After header), then tries again.
     * It will do this indefinitely unless the number of retries is limited,
     * in which case WikimateException is thrown once the limit is reached.
     *
     * The string type for $data is used only for upload POST requests,
     * and must contain the complete multipart body, including maxlag.
     *
     * @param array|string $data    Data for the request
     * @param array        $headers Optional extra headers to send with the request
     * @param bool         $post    True to send a POST request, otherwise GET
     *
     * @return array The API response
     * @throw  WikimateException       If lagged and ran out of retries,
     *                                 or got an unexpected API response
     */
    private function request(array|string $data, array $headers = [], bool $post = false): array
    {
        $retries = 0;

        // Add format & maxlag parameter to request
        if (is_array($data)) {
            $data['format'] = 'json';
            $data['maxlag'] = $this->getMaxlag();
            $action = $data['action'];
        } else {
            $action = 'upload';
        }
        // Define type of HTTP request for messages
        $httptype = $post ? 'POST' : 'GET';

        // Send appropriate type of request, once or multiple times
        do {
            if ($post) {
                $response = $this->session->post($this->api, $headers, $data);
            } else {
                $response = $this->session->get($this->api.'?'.http_build_query($data), $headers);
            }

            // Check for replication lag error
            $serverLagged = (null !== $response->headers->offsetGet('X-Database-Lag'));
            if ($serverLagged) {
                // Determine recommended or default delay
                if (null !== $response->headers->offsetGet('Retry-After')) {
                    $sleep = (int) $response->headers->offsetGet('Retry-After');
                } else {
                    $sleep = $this->getMaxlag();
                }

                sleep($sleep);

                // Check retries limit
                if ($this->getMaxretries() >= 0) {
                    ++$retries;
                } else {
                    $retries = -1; // continue indefinitely
                }
            }
        } while ($serverLagged && $retries <= $this->getMaxretries());

        // Throw exception if we ran out of retries
        if ($serverLagged) {
            throw new WikimateException("Server lagged ($retries consecutive maxlag responses)");
        }

        // Check if we got the API doc page (invalid request)
        if (false !== strpos($response->body, 'This is an auto-generated MediaWiki API documentation page')) {
            throw new WikimateException("The API could not understand the $action $httptype request");
        }

        // Check if we got a JSON result
        try {
            $result = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WikimateException("The API did not return a valid $action JSON response; '{$e->getMessage()}' was thrown");
        }

        return $result;
    }

    /**
     * Obtains a wiki token for logging in or data-modifying actions.
     *
     * If a CSRF (default) token is requested, it is stored and returned
     * upon further such requests, instead of making another API call.
     * The stored token is discarded via {@see Wikimate::logout()}.
     *
     * For now this method, in Wikimate tradition, is kept simple and supports
     * only the two token types needed elsewhere in the library.  It also
     * doesn't support the option to request multiple tokens at once.
     * See {@see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Tokens}
     * for more information.
     *
     * @param string $type The token type
     *
     * @return string|null The requested token (string), or null if error
     */
    protected function token(string $type = self::TOKEN_DEFAULT): ?string
    {
        // Check for supported token types
        if (self::TOKEN_DEFAULT != $type && self::TOKEN_LOGIN != $type) {
            $this->error = [];
            $this->error['token'] = 'The API does not support the token type';

            return null;
        }

        // Check for existing CSRF token for this login session
        if (self::TOKEN_DEFAULT == $type && null !== $this->csrfToken) {
            return $this->csrfToken;
        }

        $details = [
            'action' => 'query',
            'meta' => 'tokens',
            'type' => $type,
        ];

        // Send the token request
        $tokenResult = $this->request($details, [], true);

        // Check for errors
        if (isset($tokenResult['error'])) {
            $this->error = $tokenResult['error']; // Set the error if there was one

            return null;
        } else {
            $this->error = null; // Reset the error status
        }

        if (self::TOKEN_LOGIN == $type) {
            return $tokenResult['query']['tokens']['logintoken'];
        } else {
            // Store CSRF token for this login session
            $this->csrfToken = $tokenResult['query']['tokens']['csrftoken'];

            return $this->csrfToken;
        }
    }

    /**
     * Logs in to the wiki.
     *
     * @param string      $username The user name
     * @param string      $password The user password
     * @param string|null $domain   The domain (optional)
     *
     * @return bool True if logged in
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Login#Method_1._login
     */
    public function login(string $username, string $password, ?string $domain = null): bool
    {
        // Obtain login token first
        if (($logintoken = $this->token(self::TOKEN_LOGIN)) === null) {
            return false;
        }

        $details = [
            'action' => 'login',
            'lgname' => $username,
            'lgpassword' => $password,
            'lgtoken' => $logintoken,
        ];

        // If $domain is provided, set the corresponding detail in the request information array
        if (is_string($domain)) {
            $details['lgdomain'] = $domain;
        }

        // Send the login request
        $loginResult = $this->request($details, [], true);

        // Check for errors
        if (isset($loginResult['error'])) {
            $this->error = $loginResult['error']; // Set the error if there was one

            return false;
        } else {
            $this->error = null; // Reset the error status
        }

        if (isset($loginResult['login']['result']) && 'Success' != $loginResult['login']['result']) {
            // Some more comprehensive error checking
            $this->error = [];
            switch ($loginResult['login']['result']) {
                case 'Failed':
                    $this->error['auth'] = 'Incorrect username or password';
                    break;
                default:
                    $this->error['auth'] = 'The API result was: '.$loginResult['login']['result'];
                    break;
            }

            return false;
        }
        $this->loggedIn = true;

        return true;
    }

    /**
     * Logs out of the wiki and discard CSRF token.
     *
     * @return bool True if logged out
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Logout
     */
    public function logout(): bool
    {
        // Obtain logout token first
        if (($logouttoken = $this->token()) === null) {
            return false;
        }

        // Token is needed in MediaWiki v1.34+, older versions produce an
        // 'Unrecognized parameter' warning which can be ignored
        $details = [
            'action' => 'logout',
            'token' => $logouttoken,
        ];

        // Send the logout request
        $logoutResult = $this->request($details, [], true);

        // Check for errors
        if (isset($logoutResult['error'])) {
            $this->error = $logoutResult['error']; // Set the error if there was one

            return false;
        } else {
            $this->error = null; // Reset the error status
        }

        // Discard CSRF token for this login session
        $this->csrfToken = null;

        $this->loggedIn = false;

        return true;
    }

    /**
     * Gets the current value of the maxlag parameter.
     *
     * @return int The maxlag value in seconds
     */
    public function getMaxlag(): int
    {
        return $this->maxlag;
    }

    /**
     * Sets the new value of the maxlag parameter.
     *
     * @param int $ml The new maxlag value in seconds
     *
     * @return Wikimate This object
     */
    public function setMaxlag(int $ml): Wikimate
    {
        $this->maxlag = $ml;

        return $this;
    }

    /**
     * Gets the current value of the max retries limit.
     *
     * @return int The max retries limit
     */
    public function getMaxretries(): int
    {
        return $this->maxretries;
    }

    /**
     * Sets the new value of the max retries limit.
     *
     * @param int $mr The new max retries limit
     *
     * @return Wikimate This object
     */
    public function setMaxretries(int $mr): Wikimate
    {
        $this->maxretries = $mr;

        return $this;
    }

    /**
     * Gets the user agent for API requests.
     *
     * @return string The default user agent, or the current one defined
     *                by {@see Wikimate::setUserAgent()}
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Etiquette#The_User-Agent_header
     */
    public function getUserAgent(): string
    {
        return $this->useragent;
    }

    /**
     * Sets the user agent for API requests.
     *
     * In order to use a custom user agent for all requests in the session,
     * call this method before invoking {@see Wikimate::login()}.
     *
     * @param string $ua The new user agent
     *
     * @return Wikimate This object
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Etiquette#The_User-Agent_header
     */
    public function setUserAgent(string $ua): Wikimate
    {
        $this->useragent = $ua;
        // Update the session
        $this->session->useragent = $this->useragent;

        return $this;
    }

    /**
     * Returns a WikiPage object populated with the page data.
     *
     * @param string $title The name of the wiki article
     *
     * @return WikiPage The page object
     */
    public function getPage(string $title): WikiPage
    {
        return new WikiPage($title, $this);
    }

    /**
     * Returns a WikiFile object populated with the file data.
     *
     * @param string $filename The name of the wiki file
     *
     * @return WikiFile The file object
     */
    public function getFile(string $filename): WikiFile
    {
        return new WikiFile($filename, $this);
    }

    /**
     * Performs a query to the wiki API with the given details.
     *
     * @param array $array Array of details to be passed in the query
     *
     * @return array Decoded JSON output from the wiki API
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Query
     */
    public function query(array $array): array
    {
        $array['action'] = 'query';

        return $this->request($array);
    }

    /**
     * Performs a parse query to the wiki API.
     *
     * @param array $array Array of details to be passed in the query
     *
     * @return array Decoded JSON output from the wiki API
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Parsing_wikitext
     */
    public function parse(array $array): array
    {
        $array['action'] = 'parse';

        return $this->request($array);
    }

    /**
     * Perfoms an edit query to the wiki API.
     *
     * @param array $array Array of details to be passed in the query
     *
     * @return array|false Decoded JSON output from the wiki API
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Edit
     */
    public function edit(array $array): array|false
    {
        // Obtain default token first
        if (($edittoken = $this->token()) === null) {
            return false;
        }

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $array['action'] = 'edit';
        $array['token'] = $edittoken;

        return $this->request($array, $headers, true);
    }

    /**
     * Perfoms a delete query to the wiki API.
     *
     * @param array $array Array of details to be passed in the query
     *
     * @return array|false Decoded JSON output from the wiki API
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Delete
     */
    public function delete(array $array): array|false
    {
        // Obtain default token first
        if (($deletetoken = $this->token()) === null) {
            return false;
        }

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $array['action'] = 'delete';
        $array['token'] = $deletetoken;

        return $this->request($array, $headers, true);
    }

    /**
     * Downloads data from the given URL.
     *
     * @param string $url The URL to download from
     *
     * @return ?string The downloaded data (string), or null if error
     */
    public function download(string $url): ?string
    {
        $getResult = $this->session->get($url);

        if (!$getResult->success) {
            // Debug logging of Requests_Response only on failed download
            if ($this->debugMode) {
                echo "download GET response:\n";
                print_r($getResult);
            }
            $this->error = [];
            $this->error['file'] = 'Download error (HTTP status: '.$getResult->status_code.')';
            $this->error['http'] = $getResult->status_code;

            return null;
        }

        return $getResult->body;
    }

    /**
     * Uploads a file to the wiki API.
     *
     * @param array $array Array of details to be used in the upload
     *
     * @return array|bool Decoded JSON output from the wiki API
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Upload
     */
    public function upload(array $array): array|bool
    {
        // Obtain default token first
        if (($uploadtoken = $this->token()) === null) {
            return false;
        }

        $array['action'] = 'upload';
        $array['format'] = 'json';
        $array['maxlag'] = $this->getMaxlag();
        $array['token'] = $uploadtoken;

        // Construct multipart body:
        // https://www.mediawiki.org/w/index.php?title=API:Upload&oldid=2293685#Sample_Raw_Upload
        // https://www.mediawiki.org/w/index.php?title=API:Upload&oldid=2339771#Sample_Raw_POST_of_a_single_chunk
        $boundary = '---Wikimate-'.md5(microtime());
        $body = '';
        foreach ($array as $fieldName => $fieldData) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="'.$fieldName.'"';
            // Process the (binary) file
            if ('file' == $fieldName) {
                $body .= '; filename="'.$array['filename'].'"'."\r\n";
                $body .= "Content-Type: application/octet-stream; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: binary\r\n";
            // Process text parameters
            } else {
                $body .= "\r\n";
                $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: 8bit\r\n";
            }
            $body .= "\r\n{$fieldData}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        // Construct multipart headers
        $headers = [
            'Content-Type' => "multipart/form-data; boundary={$boundary}",
            'Content-Length' => strlen($body),
        ];

        return $this->request($body, $headers, true);
    }

    /**
     * Performs a file revert query to the wiki API.
     *
     * @param array $array Array of details to be passed in the query
     *
     * @return array|false Decoded JSON output from the wiki API
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Filerevert
     */
    public function filerevert(array $array): array|false
    {
        // Obtain default token first
        if (($reverttoken = $this->token()) === null) {
            return false;
        }

        $array['action'] = 'filerevert';
        $array['token'] = $reverttoken;

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        return $this->request($array, $headers, true);
    }

    /**
     * Returns the latest error if there is one.
     *
     * @return ?array The error array, or null if no error
     */
    public function getError(): ?array
    {
        return $this->error;
    }

    /**
     * determines if the session is logged in.
     */
    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }
}
