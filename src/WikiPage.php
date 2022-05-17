<?php

declare(strict_types=1);

namespace NNS\Wikimate;

use stdClass;
use UnexpectedValueException;

/**
 * Models a wiki article page that can have its text altered and retrieved.
 *
 * @author Robert McLeod & Frans P. de Vries
 * @author Nikolai Neff
 *
 * @since   0.2  December 2010
 */
class WikiPage
{
    /**
     * Use section indexes as keys in return array of {@see WikiPage::getAllSections()}.
     *
     * @var int
     */
    public const SECTIONLIST_BY_INDEX = 1;

    /**
     * Use section names as keys in return array of {@see WikiPage::getAllSections()}.
     *
     * @var int
     */
    public const SECTIONLIST_BY_NAME = 2;

    /**
     * The title of the page.
     */
    protected string $title;

    /**
     * Wikimate object for API requests.
     */
    protected Wikimate $wikimate;

    /**
     * Whether the page exists.
     */
    protected bool $exists = false;

    /**
     * Whether the page is invalid.
     */
    protected bool $invalid = false;

    /**
     * Error array with API and WikiPage errors.
     *
     * @var array|null
     */
    protected ?array $error = null;

    /**
     * Stores the timestamp for detection of edit conflicts.
     */
    protected ?int $starttimestamp = null;

    /**
     * The complete text of the page.
     */
    protected ?string $text = null;

    /**
     * The sections object for the page.
     */
    protected stdClass $sections;

    /**
     * Constructs a WikiPage object from the title given
     * and associate with the passed Wikimate object.
     *
     * @param string   $title    Name of the wiki article
     * @param Wikimate $wikimate Wikimate object
     */
    public function __construct(string $title, Wikimate $wikimate)
    {
        $this->wikimate = $wikimate;
        $this->title = $title;
        $this->sections = new stdClass();
        $this->text = $this->getText(true);

        if ($this->invalid) {
            $this->error['page'] = 'Invalid page title - cannot create WikiPage';
        }
    }

    /**
     * Returns the wikicode of the page.
     *
     * @return string String of wikicode
     */
    public function __toString(): string
    {
        return (string) $this->text;
    }

    /**
     * Returns an array sections with the section name as the key
     * and the text as the element, e.g.
     *
     * array(
     *   'intro' => 'this text is the introduction',
     *   'History' => 'this is text under the history section'
     *)
     *
     * @return array Array of sections
     */
    public function __invoke(): array
    {
        return $this->getAllSections(false, self::SECTIONLIST_BY_NAME);
    }

    /**
     * Returns the page existence status.
     *
     * @return bool True if page exists
     */
    public function exists(): bool
    {
        return $this->exists;
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
     * Returns the title of this page.
     *
     * @return string The title of this page
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Returns the number of sections in this page.
     *
     * @return int The number of sections in this page
     */
    public function getNumSections(): int
    {
        return count($this->sections->byIndex);
    }

    /**
     * Returns the sections offsets and lengths.
     *
     * @return stdClass Section class
     */
    public function getSectionOffsets(): ?stdClass
    {
        return $this->sections;
    }

    /**
     * Gets the text of the page. If refresh is true,
     * then this method will query the wiki API again for the page details.
     *
     * @param bool $refresh True to query the wiki API again
     *
     * @return ?string The text of the page (string), or null if error
     */
    public function getText(bool $refresh = false): ?string
    {
        if ($refresh) { // We want to query the API
            // Specify relevant page properties to retrieve
            $data = [
                'titles' => $this->title,
                'prop' => 'info|revisions',
                'rvprop' => 'content', // Need to get page text
                'curtimestamp' => 1,
            ];

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

            // Abort if invalid page title
            if (isset($page['invalid'])) {
                $this->invalid = true;

                return null;
            }

            $this->starttimestamp = (int) $r['curtimestamp'];
            unset($r, $data);

            if (!isset($page['missing'])) {
                // Update the existence if the page is there
                $this->exists = true;
                // Put the content into text
                $this->text = $page['revisions'][0]['*'];
            }
            unset($page);

            // Now we need to get the section headers, if any
            if (is_null($this->text)){
                return null;
            } else {
                preg_match_all('/(={1,6}).*?\1 *(?:\n|$)/', $this->text, $matches);
            }

            // Set the intro section (between title and first section)
            $this->sections->byIndex[0]['offset'] = 0;
            $this->sections->byName['intro']['offset'] = 0;

            // Check for section header matches
            if (empty($matches[0])) {
                // Define lengths for page consisting only of intro section
                $this->sections->byIndex[0]['length'] = strlen($this->text);
                $this->sections->byName['intro']['length'] = strlen($this->text);
            } else {
                // Array of section header matches
                $sections = $matches[0];

                // Set up the current section
                $currIndex = 0;
                $currName = 'intro';

                // Collect offsets and lengths from section header matches
                foreach ($sections as $section) {
                    // Get the current offset
                    $currOffset = strpos($this->text, $section, $this->sections->byIndex[$currIndex]['offset']);

                    // Are we still on the first section?
                    if (0 == $currIndex) {
                        $this->sections->byIndex[$currIndex]['length'] = $currOffset;
                        $this->sections->byIndex[$currIndex]['depth'] = 0;
                        $this->sections->byName[$currName]['length'] = $currOffset;
                        $this->sections->byName[$currName]['depth'] = 0;
                    }

                    // Get the current name and index
                    $currName = trim(str_replace('=', '', $section));
                    ++$currIndex;

                    // Search for existing name and create unique one
                    $cName = $currName;
                    for ($seq = 2; array_key_exists($cName, $this->sections->byName); ++$seq) {
                        $cName = $currName.'_'.$seq;
                    }
                    if ($seq > 2) {
                        $currName = $cName;
                    }

                    // Set the offset and depth (from the matched ='s) for the current section
                    $this->sections->byIndex[$currIndex]['offset'] = $currOffset;
                    $this->sections->byIndex[$currIndex]['depth'] = strlen($matches[1][$currIndex - 1]);
                    $this->sections->byName[$currName]['offset'] = $currOffset;
                    $this->sections->byName[$currName]['depth'] = strlen($matches[1][$currIndex - 1]);

                    // If there is a section after this, set the length of this one
                    if (isset($sections[$currIndex])) {
                        // Get the offset of the next section
                        $nextOffset = strpos($this->text, $sections[$currIndex], $currOffset);
                        // Calculate the length of this one
                        $length = $nextOffset - $currOffset;

                        // Set the length of this section
                        $this->sections->byIndex[$currIndex]['length'] = $length;
                        $this->sections->byName[$currName]['length'] = $length;
                    } else {
                        // Set the length of last section
                        $this->sections->byIndex[$currIndex]['length'] = strlen($this->text) - $currOffset;
                        $this->sections->byName[$currName]['length'] = strlen($this->text) - $currOffset;
                    }
                }
            }
        }

        return $this->text; // Return the text in any case
    }

    /**
     * Returns the requested section, with its subsections, if any.
     *
     * Section can be the following:
     * - section name (string, e.g. "History")
     * - section index (int, e.g. 3)
     *
     * @param int|string $section            The section to get
     * @param bool       $includeHeading     False to get section text only,
     *                                       true to include heading too
     * @param bool       $includeSubsections False to get section text only,
     *                                       true to include subsections too
     *
     * @return ?string Wikitext of the section on the page,
     *                 or null if section is undefined
     */
    public function getSection(string|int $section, bool $includeHeading = false, bool $includeSubsections = true): ?string
    {
        // Check if we have a section name or index
        if (is_int($section)) {
            if (!isset($this->sections->byIndex[$section])) {
                return null;
            }
            $coords = $this->sections->byIndex[$section];
        } else {
            if (!isset($this->sections->byName[$section])) {
                return null;
            }
            $coords = $this->sections->byName[$section];
        }

        // Extract the offset, depth and (initial) length
        extract($coords); //ToDo: Fix me and don't use extract
        // Find subsections if requested, and not the intro
        if ($includeSubsections && $offset > 0) {
            $found = false;
            foreach ($this->sections->byName as $section) {
                if ($found) {
                    // Include length of this subsection
                    if ($depth < $section['depth']) {
                        $length += $section['length'];
                    // Done if not a subsection
                    } else {
                        break;
                    }
                } else {
                    // Found our section if same offset
                    if ($offset == $section['offset']) {
                        $found = true;
                    }
                }
            }
        }
        // Extract text of section, and its subsections if requested
        $text = substr($this->text, $offset, $length);

        // Whack off the heading if requested, and not the intro
        if (!$includeHeading && $offset > 0) {
            // Chop off the first line
            $text = substr($text, strpos($text, "\n"));
        }

        return $text;
    }

    /**
     * Returns all the sections of the page in an array - the key names can be
     * set to name or index by using the following for the second param:
     * - self::SECTIONLIST_BY_NAME
     * - self::SECTIONLIST_BY_INDEX.
     *
     * @param bool $includeHeading False to get section text only
     * @param int  $keyNames       Modifier for the array key names
     *
     * @return array Array of sections
     *
     * @throws UnexpectedValueException If $keyNames is not a supported constant
     */
    public function getAllSections(bool $includeHeading = false, int $keyNames = self::SECTIONLIST_BY_INDEX): array
    {
        $sections = [];

        switch ($keyNames) {
            case self::SECTIONLIST_BY_INDEX:
                $array = array_keys($this->sections->byIndex);
                break;
            case self::SECTIONLIST_BY_NAME:
                $array = array_keys($this->sections->byName);
                break;
            default:
                throw new UnexpectedValueException("Unexpected keyNames parameter ($keyNames) passed to WikiPage::getAllSections()");
        }

        foreach ($array as $key) {
            $sections[$key] = $this->getSection($key, $includeHeading);
        }

        return $sections;
    }

    /**
     * Sets the text in the page.  Updates the starttimestamp to the timestamp
     * after the page edit (if the edit is successful).
     *
     * Section can be the following:
     * - section name (string, e.g. "History")
     * - section index (int, e.g. 3)
     * - a new section (the string "new")
     * - the whole page (null)
     *
     * @param string          $text    The article text
     * @param int|string|null $section The section to edit (whole page by default)
     * @param bool            $minor   True for minor edit
     * @param ?string         $summary Summary text, and section header in case
     *                                 of new section
     *
     * @return bool True if page was edited successfully
     */
    public function setText(string $text, int|string|null $section = null, bool $minor = false, ?string $summary = null): bool
    {
        $data = [
            'title' => $this->title,
            'text' => $text,
            'md5' => md5($text),
            'bot' => 'true',
            'starttimestamp' => $this->starttimestamp,
        ];

        // Set options from arguments
        if (!is_null($section)) {
            // Obtain section index in case it is a name
            $data['section'] = $this->findSection($section);
            if (-1 == $data['section']) {
                return false;
            }
        }
        if ($minor) {
            $data['minor'] = $minor;
        }
        if (!is_null($summary)) {
            $data['summary'] = $summary;
        }

        // Make sure we don't create a page by accident or overwrite another one
        if (!$this->exists) {
            $data['createonly'] = 'true'; // createonly if not exists
        } else {
            $data['nocreate'] = 'true'; // Don't create, it should exist
        }

        $r = $this->wikimate->edit($data); // The edit query

        // Check if it worked
        if (isset($r['edit']['result']) && 'Success' == $r['edit']['result']) {
            $this->exists = true;

            if (is_null($section)) {
                $this->text = $text;
            }

            // Get the new starttimestamp
            $data = [
                'titles' => $this->title,
                'prop' => 'info',
                'curtimestamp' => 1,
            ];

            $r = $this->wikimate->query($data);

            // Check for errors
            if (isset($r['error'])) {
                $this->error = $r['error']; // Set the error if there was one

                return false;
            } else {
                $this->error = null; // Reset the error status
            }

            $this->starttimestamp = (int) $r['curtimestamp']; // Update the starttimestamp

            return true;
        }

        // Return error response
        if (isset($r['error'])) {
            $this->error = $r['error'];
        } else {
            $this->error = [];
            if (isset($r['edit']['captcha'])) {
                $this->error['page'] = 'Edit denied by CAPTCHA';
            } else {
                $this->error['page'] = 'Unexpected edit response: '.$r['edit']['result'];
            }
        }

        return false;
    }

    /**
     * Sets the text of the given section.
     * Essentially an alias of WikiPage:setText()
     * with the summary and minor parameters switched.
     *
     * Section can be the following:
     * - section name (string, e.g. "History")
     * - section index (int, e.g. 3)
     * - a new section (the string "new")
     * - the whole page (null)
     *
     * @param string     $text    The text of the section
     * @param int|string $section The section to edit
     * @param string     $summary Summary text, and section header in case
     *                            of new section
     * @param bool       $minor   True for minor edit
     *
     * @return bool True if the section was saved
     */
    public function setSection(string $text, int|string $section, ?string $summary = null, bool $minor = false): bool
    {
        return $this->setText($text, $section, $minor, $summary);
    }

    /**
     * Alias of WikiPage::setSection() specifically for creating new sections.
     *
     * @param string $name The heading name for the new section
     * @param string $text The text of the new section
     *
     * @return bool True if the section was saved
     */
    public function newSection(string $name, string $text): bool
    {
        return $this->setSection($text, 'new', $name, false);
    }

    /**
     * Deletes the page.
     *
     * @param string $reason Reason for the deletion
     *
     * @return bool True if page was deleted successfully
     */
    public function delete(?string $reason = null): bool
    {
        $data = [
            'title' => $this->title,
        ];

        // Set options from arguments
        if (!is_null($reason)) {
            $data['reason'] = $reason;
        }

        $r = $this->wikimate->delete($data); // The delete query

        // Check if it worked
        if (isset($r['delete'])) {
            $this->exists = false; // The page was deleted

            $this->error = null; // Reset the error status

            return true;
        }

        $this->error = $r['error']; // Return error response

        return false;
    }

    /**
     * Finds a section's index by name.
     * If a section index or 'new' is passed, it is returned directly.
     *
     * @param int|string $section The section name or index to find
     *
     * @return mixed The section index, or -1 if not found
     */
    private function findSection(int|string $section): mixed
    {
        // Check section type
        if (is_int($section) || 'new' === $section) {
            return $section;
        } elseif (is_string($section)) {
            // Search section names for related index
            $sections = array_keys($this->sections->byName);
            $index = array_search($section, $sections);

            // Return index if found
            if (false !== $index) {
                return $index;
            }
        }

        // Return error message and value
        $this->error = [];
        $this->error['page'] = "Section '$section' was not found on this page";

        return -1;
    }
}
