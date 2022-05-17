<?php

declare(strict_types=1);

namespace NNS\Wikimate;

/**
 * Models a wiki file that can have its properties retrieved and
 * its contents downloaded and uploaded.
 * All properties pertain to the current revision of the file.
 *
 * @author  Robert McLeod & Frans P. de Vries
 *
 * @since   0.12.0  October 2016
 */
class WikiFile
{
    /**
     * The name of the file.
     */
    protected ?string $filename = null;

    /**
     * Wikimate object for API requests.
     */
    protected Wikimate $wikimate;

    /**
     * Whether the file exists.
     */
    protected bool $exists = false;

    /**
     * Whether the file is invalid.
     */
    protected bool $invalid = false;

    /**
     * Error array with API and WikiFile errors.
     */
    protected ?array $error = null;

    /**
     * Image info for the current file revision.
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Imageinfo
     */
    protected ?array $info = null;

    /**
     * Image info for all file revisions.
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Imageinfo
     */
    protected ?array $history = null;

    /**
     * Constructs a WikiFile object from the filename given
     * and associate with the passed Wikimate object.
     *
     * @param string   $filename Name of the wiki file
     * @param Wikimate $wikimate Wikimate object
     */
    public function __construct(string $filename, Wikimate $wikimate)
    {
        $this->wikimate = $wikimate;
        $this->filename = $filename;
        $this->info = $this->getInfo(true);

        if ($this->invalid) {
            $this->error['file'] = 'Invalid filename - cannot create WikiFile';
        }
    }

    /**
     * Returns the file existence status.
     *
     * @return bool True if file exists
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Alias of self::__destruct().
     */
    public function destroy(): void
    {
        $this->__destruct();
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
     * Returns the name of this file.
     *
     * @return ?string The name of this file
     */
    public function getFilename(): ?string
    {
        return $this->filename;
    }

    /**
     * Gets the information of the file. If refresh is true,
     * then this method will query the wiki API again for the file details.
     *
     * @param bool  $refresh True to query the wiki API again
     * @param array $history An optional array of revision history parameters
     *
     * @return ?array The info of the file (array), or null if error
     */
    public function getInfo(bool $refresh = false, ?array $history = null): ?array
    {
        if ($refresh) { // We want to query the API
            // Specify relevant file properties to retrieve
            $data = [
                'titles' => 'File:'.$this->filename,
                'prop' => 'info|imageinfo',
                'iiprop' => 'badfile|bitdepth|canonicaltitle|comment|commonmetadata|dimensions|extmetadata|mediatype|'.
                            'metadata|mime|parsedcomment|sha1|size|thumbmime|timestamp|uploadwarning|url|user|userid',
            ];
            // Add optional history parameters
            if (is_array($history)) {
                foreach ($history as $key => $val) {
                    $data[$key] = $val;
                }
                // Retrieve archive name property as well
                $data['iiprop'] .= '|archivename';
            }

            $r = $this->wikimate->query($data); // Run the query

            // Check for errors
            if (isset($r['error'])) {
                $this->error = $r['error']; // Set the error if there was one

                return null;
            } else {
                $this->error = null; // Reset the error status
            }

            // Get the page (there should only be one)
            $page = array_pop($r['query']['pages']);
            unset($r, $data);

            // Abort if invalid file title
            if (isset($page['invalid'])) {
                $this->invalid = true;

                return null;
            }

            // Check that file is present and has info
            if (!isset($page['missing']) && isset($page['imageinfo'])) {
                // Update the existence if the file is there
                $this->exists = true;
                // Put the content into info & history
                $this->info = $page['imageinfo'][0];
                $this->history = $page['imageinfo'];
            }
            unset($page);
        }

        return $this->info; // Return the info in any case
    }

    /**
     * Returns the anonymous flag of this file,
     * or of its specified revision.
     * If true, then getUser()'s value represents an anonymous IP address.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?bool The anonymous flag of this file (boolean),
     *               or null if revision not found
     */
    public function getAnon(string|int|null $revision = null): ?bool
    {
        // Without revision, use current info
        if (!isset($revision)) {
            // Check for anon flag
            return isset($this->info['anon']) ? true : false;
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        // Check for anon flag
        return isset($info['anon']) ? true : false;
    }

    /**
     * Returns the aspect ratio of this image,
     * or of its specified revision.
     * Returns 0 if file is not an image (and thus has no dimensions).
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return float The aspect ratio of this image, or 0 if no dimensions,
     *               or -1 if revision not found
     */
    public function getAspectRatio(string|int|null $revision = null): float
    {
        // Without revision, use current info
        if (!isset($revision)) {
            // Check for dimensions
            if (isset($this->info['height'], $this->info['width']) && $this->info['height'] > 0) {
                return $this->info['width'] / $this->info['height'];
            } else {
                return 0;
            }
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return -1;
        }

        // Check for dimensions
        if (isset($info['height'])) {
            return $info['width'] / $info['height'];
        } else {
            return 0;
        }
    }

    /**
     * Returns the bit depth of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return int The bit depth of this file,
     *             or -1 if revision not found
     */
    public function getBitDepth(string|int|null $revision = null): int
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return (int) $this->info['bitdepth'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return -1;
        }

        return (int) $info['bitdepth'];
    }

    /**
     * Returns the canonical title of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The canonical title of this file (string),
     *                 or null if revision not found
     */
    public function getCanonicalTitle(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['canonicaltitle'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['canonicaltitle'];
    }

    /**
     * Returns the edit comment of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The edit comment of this file (string),
     *                 or null if revision not found
     */
    public function getComment(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['comment'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['comment'];
    }

    /**
     * Returns the common metadata of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?array The common metadata of this file (array),
     *                or null if revision not found
     */
    public function getCommonMetadata(string|int|null $revision = null): ?array
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['commonmetadata'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['commonmetadata'];
    }

    /**
     * Returns the description URL of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The description URL of this file (string),
     *                 or null if revision not found
     */
    public function getDescriptionUrl(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['descriptionurl'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['descriptionurl'];
    }

    /**
     * Returns the extended metadata of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?array The extended metadata of this file (array),
     *                or null if revision not found
     */
    public function getExtendedMetadata(string|int|null $revision = null): ?array
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['extmetadata'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['extmetadata'];
    }

    /**
     * Returns the height of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return int The height of this file, or -1 if revision not found
     */
    public function getHeight(string|int|null $revision = null): int
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return (int) $this->info['height'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return -1;
        }

        return (int) $info['height'];
    }

    /**
     * Returns the media type of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The media type of this file (string),
     *                 or null if revision not found
     */
    public function getMediaType(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['mediatype'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['mediatype'];
    }

    /**
     * Returns the Exif metadata of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?array The metadata of this file (array),
     *                or null if revision not found
     */
    public function getMetadata(string|int|null $revision = null): ?array
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['metadata'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['metadata'];
    }

    /**
     * Returns the MIME type of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The MIME type of this file (string),
     *                 or null if revision not found
     */
    public function getMime(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['mime'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['mime'];
    }

    /**
     * Returns the parsed edit comment of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The parsed edit comment of this file (string),
     *                 or null if revision not found
     */
    public function getParsedComment(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['parsedcomment'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['parsedcomment'];
    }

    /**
     * Returns the SHA-1 hash of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The SHA-1 hash of this file (string),
     *                 or null if revision not found
     */
    public function getSha1(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['sha1'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['sha1'];
    }

    /**
     * Returns the size of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return int The size of this file, or -1 if revision not found
     */
    public function getSize(string|int|null $revision = null): int
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return (int) $this->info['size'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return -1;
        }

        return (int) $info['size'];
    }

    /**
     * Returns the MIME type of this file's thumbnail,
     * or of its specified revision.
     * Returns empty string if property not available for this file type.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The MIME type of this file's thumbnail (string),
     *                 or '' if unavailable, or null if revision not found
     */
    public function getThumbMime(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return isset($this->info['thumbmime']) ? $this->info['thumbmime'] : '';
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        // Check for thumbnail MIME type
        return $info['thumbmime'] ?? '';
    }

    /**
     * Returns the timestamp of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The timestamp of this file (string),
     *                 or null if revision not found
     */
    public function getTimestamp(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['timestamp'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['timestamp'];
    }

    /**
     * Returns the URL of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The URL of this file (string),
     *                 or null if revision not found
     */
    public function getUrl(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['url'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['url'];
    }

    /**
     * Returns the user who uploaded this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return ?string The user of this file (string),
     *                 or null if revision not found
     */
    public function getUser(string|int|null $revision = null): ?string
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return $this->info['user'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        return $info['user'];
    }

    /**
     * Returns the ID of the user who uploaded this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return int The user ID of this file,
     *             or -1 if revision not found
     */
    public function getUserId(string|int|null $revision = null): int
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return (int) $this->info['userid'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return -1;
        }

        return (int) $info['userid'];
    }

    /**
     * Returns the width of this file,
     * or of its specified revision.
     *
     * @param string|int|null $revision The index or timestamp of the revision (optional)
     *
     * @return int The width of this file, or -1 if revision not found
     */
    public function getWidth(string|int|null $revision = null): int
    {
        // Without revision, use current info
        if (!isset($revision)) {
            return (int) $this->info['width'];
        }

        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return -1;
        }

        return (int) $info['width'];
    }

    /*
     *
     * File history & deletion methods
     *
     */

    /**
     * Returns the revision history of this file with all properties.
     * The initial history at object creation contains only the
     * current revision of the file. To obtain more revisions,
     * set $refresh to true and also optionally set $limit and
     * the timestamps.
     *
     * The maximum limit is 500 for user accounts and 5000 for bot accounts.
     *
     * Timestamps can be in several formats as described here:
     * {@see https://www.mediawiki.org/w/api.php?action=help&modules=main#main.2Fdatatypes}
     *
     * @param bool    $refresh True to query the wiki API again
     * @param ?int    $limit   The number of file revisions to return
     *                         (the maximum number by default)
     * @param ?string $startts The start timestamp of the listing (optional)
     * @param ?string $endts   The end timestamp of the listing (optional)
     *
     * @return ?array The array of selected file revisions, or null if error
     */
    public function getHistory(bool $refresh = false, ?int $limit = null, ?string $startts = null, ?string $endts = null): ?array
    {
        if ($refresh) { // We want to query the API
            // Collect optional history parameters
            $history = [];
            if (!is_null($limit)) {
                $history['iilimit'] = $limit;
            } else {
                $history['iilimit'] = 'max';
            }
            if (!is_null($startts)) {
                $history['iistart'] = $startts;
            }
            if (!is_null($endts)) {
                $history['iiend'] = $endts;
            }

            // Get file revision history
            if (null === $this->getInfo($refresh, $history)) {
                return null;
            }
        }

        return $this->history;
    }

    /**
     * Returns the properties of the specified file revision.
     *
     * Revision can be the following:
     * - revision timestamp (string, e.g. "2001-01-15T14:56:00Z")
     * - revision index (int, e.g. 3)
     * The most recent revision has index 0,
     * and it increments towards older revisions.
     * A timestamp must be in ISO 8601 format.
     *
     * @param string|int|null $revision The index or timestamp of the revision
     *
     * @return ?array The properties (array), or null if not found
     */
    public function getRevision(string|int|null $revision): ?array
    {
        // Select revision by index
        if (is_int($revision)) {
            if (isset($this->history[$revision])) {
                return $this->history[$revision];
            }
            // Search revision by timestamp
        } else {
            if (!is_null($this->history)) {
                foreach ($this->history as $history) {
                    if ($history['timestamp'] == $revision) {
                        return $history;
                    }
                }
            } else {
                $this->error = [];
                $this->error['file'] = "History for Revision '$revision' is null";

                return null;
            }
        }

        // Return error message
        $this->error = [];
        $this->error['file'] = "Revision '$revision' was not found for this file";

        return null;
    }

    /**
     * Returns the archive name of the specified file revision.
     *
     * Revision can be the following:
     * - revision timestamp (string, e.g. "2001-01-15T14:56:00Z")
     * - revision index (int, e.g. 3)
     * The most recent revision has index 0,
     * and it increments towards older revisions.
     * A timestamp must be in ISO 8601 format.
     *
     * @param string|int|null $revision The index or timestamp of the revision
     *
     * @return ?string The archive name (string), or null if not found
     */
    public function getArchivename(string|int|null $revision): ?string
    {
        // Obtain the properties of the revision
        if (($info = $this->getRevision($revision)) === null) {
            return null;
        }

        // Check for archive name
        if (!isset($info['archivename'])) {
            // Return error message
            $this->error = [];
            $this->error['file'] = 'This revision contains no archive name';

            return null;
        }

        return $info['archivename'];
    }

    /**
     * Deletes the file, or only an older revision of it.
     *
     * @param ?string $reason      Reason for the deletion
     * @param ?string $archivename The archive name of the older revision
     *
     * @return bool True if file (revision) was deleted successfully
     */
    public function delete(?string $reason = null, ?string $archivename = null)
    {
        $data = [
            'title' => 'File:'.$this->filename,
        ];

        // Set options from arguments
        if (!is_null($reason)) {
            $data['reason'] = $reason;
        }
        if (!is_null($archivename)) {
            $data['oldimage'] = $archivename;
        }

        $r = $this->wikimate->delete($data); // The delete query

        // Check if it worked
        if (isset($r['delete'])) {
            if (is_null($archivename)) {
                $this->exists = false; // The file was deleted altogether
            }

            $this->error = null; // Reset the error status

            return true;
        }

        $this->error = $r['error']; // Return error response

        return false;
    }

    /**
     * Reverts file to an older revision.
     *
     * @param string  $archivename The archive name of the older revision
     * @param ?string $reason      Reason for the revert
     *
     * @return bool True if reverting was successful
     *
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Filerevert
     */
    public function revert(string $archivename, ?string $reason = null): bool
    {
        // Set options from arguments
        $data = [
            'filename' => $this->filename,
            'archivename' => $archivename,
        ];
        if (!is_null($reason)) {
            $data['comment'] = $reason;
        }

        $r = $this->wikimate->filerevert($data); // The revert query

        // Check if it worked
        if (isset($r['filerevert']['result']) && 'Success' == $r['filerevert']['result']) {
            $this->error = null; // Reset the error status

            return true;
        }

        $this->error = $r['error']; // Return error response

        return false;
    }

    /**
     * Downloads and returns the current file's contents,
     * or null if an error occurs.
     *
     * @return ?string Contents, or null if error
     */
    public function downloadData(): ?string
    {
        // Download file, or handle error
        if (is_null($this->getUrl())) {
            return null;
        }

        $data = $this->wikimate->download($this->getUrl());

        if (null === $data) {
            $this->error = $this->wikimate->getError(); // Copy error if there was one
        } else {
            $this->error = null; // Reset the error status
        }

        return $data;
    }

    /**
     * Downloads the current file's contents and writes it to the given path.
     *
     * @param string $path The file path to write to
     *
     * @return bool True if path was written successfully
     */
    public function downloadFile(string $path): bool
    {
        // Download contents of current file
        if (($data = $this->downloadData()) === null) {
            return false;
        }

        // Write contents to specified path
        if (false === @file_put_contents($path, $data)) {
            $this->error = [];
            $this->error['file'] = "Unable to write file '$path'";

            return false;
        }

        return true;
    }

    /**
     * Uploads to the current file using the given parameters.
     * $text is only used for the page contents of a new file,
     * not an existing one (update that via WikiPage::setText()).
     * If no $text is specified, $comment will be used as new page text.
     *
     * @param array   $params    The upload parameters
     * @param string  $comment   Upload comment for the file
     * @param ?string $text      The article text for the file page
     * @param bool    $overwrite True to overwrite existing file
     *
     * @return bool True if uploading was successful
     */
    private function uploadCommon(array $params, string $comment, ?string $text = null, bool $overwrite = false): bool
    {
        // Check whether to overwrite existing file
        if ($this->exists && !$overwrite) {
            $this->error = [];
            $this->error['file'] = 'Cannot overwrite existing file';

            return false;
        }

        // Collect upload parameters
        $params['filename'] = $this->filename;
        $params['comment'] = $comment;
        $params['ignorewarnings'] = $overwrite;
        if (!is_null($text)) {
            $params['text'] = $text;
        }

        // Upload file, or handle error
        $r = $this->wikimate->upload($params);

        if (isset($r['upload']['result']) && 'Success' == $r['upload']['result']) {
            // Update the file's properties
            $this->info = $r['upload']['imageinfo'];

            $this->error = null; // Reset the error status

            return true;
        }

        // Return error response
        if (isset($r['error'])) {
            $this->error = $r['error'];
        } else {
            $this->error = [];
            $this->error['file'] = 'Unexpected upload response: '.$r['upload']['result'];
        }

        return false;
    }

    /**
     * Uploads the given contents to the current file.
     * $text is only used for the page contents of a new file,
     * not an existing one (update that via WikiPage::setText()).
     * If no $text is specified, $comment will be used as new page text.
     *
     * @param string  $data      The data to upload
     * @param string  $comment   Upload comment for the file
     * @param ?string $text      The article text for the file page
     * @param bool    $overwrite True to overwrite existing file
     *
     * @return bool True if uploading was successful
     */
    public function uploadData(string $data, string $comment, ?string $text = null, bool $overwrite = false): bool
    {
        // Collect upload parameter
        $params = [
            'file' => $data,
        ];

        // Upload contents to current file
        return $this->uploadCommon($params, $comment, $text, $overwrite);
    }

    /**
     * Reads contents from the given path and uploads it to the current file.
     * $text is only used for the page contents of a new file,
     * not an existing one (update that via WikiPage::setText()).
     * If no $text is specified, $comment will be used as new page text.
     *
     * @param string  $path      The file path to upload
     * @param string  $comment   Upload comment for the file
     * @param ?string $text      The article text for the file page
     * @param bool    $overwrite True to overwrite existing file
     *
     * @return bool True if uploading was successful
     */
    public function uploadFile(string $path, string $comment, ?string $text = null, bool $overwrite = false): bool
    {
        // Read contents from specified path
        if (($data = file_get_contents($path)) === false) {
            $this->error = [];
            $this->error['file'] = "Unable to read file '$path'";

            return false;
        }

        // Upload contents to current file
        return $this->uploadData($data, $comment, $text, $overwrite);
    }

    /**
     * Uploads file contents from the given URL to the current file.
     * $text is only used for the page contents of a new file,
     * not an existing one (update that via WikiPage::setText()).
     * If no $text is specified, $comment will be used as new page text.
     *
     * @param string  $url       The URL from which to upload
     * @param string  $comment   Upload comment for the file
     * @param ?string $text      The article text for the file page
     * @param bool    $overwrite True to overwrite existing file
     *
     * @return bool True if uploading was successful
     */
    public function uploadFromUrl(string $url, string $comment, ?string $text = null, bool $overwrite = false): bool
    {
        // Collect upload parameter
        $params = [
            'url' => $url,
        ];

        // Upload URL to current file
        return $this->uploadCommon($params, $comment, $text, $overwrite);
    }
}
