<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This plugin is used to access Koha library systems.
 *
 * @since 2.3
 * @package    repository_koha
 * @copyright  2012 Jonathan Harker {@link http://catalyst.net.nz/}
 * @author     Jonathan Harker <jonathan@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');

/**
 * Koha repository plugin for Moodle.
 *
 * @since 2.3
 * @package    repository_koha
 * @copyright  2012 Jonathan Harker {@link http://catalyst.net.nz/}
 * @author     Jonathan Harker <jonathan@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_koha extends repository {

    /**
     * Constructor
     *
     * @param int $repositoryid repository instance id
     * @param int|stdClass $context a context id or context object
     * @param array $options repository options
     */
    public function __construct($repositoryid, $context=SYSCONTEXTID, $options=array()) {
        parent::__construct($repositoryid, $context, $options, 1);
        $this->config = get_config('koha');

        ########################################################
        # TODO: DEBUG ONLY
        # $this->config->url = 'http://www.library.org.nz/cgi-bin/koha/';
        ########################################################

        if (substr($this->config->url, -1) != '/') {
            $this->config->url = "{$this->config->url}/";
        }
    }

    /**
     * Koha plugin only return external links.
     * @return int
     */
    public function supported_returntypes() {
        return FILE_EXTERNAL;
    }

    /**
     * Filetypes
     * @return array
     */
    public function supported_filetypes() {
        return array('*');
    }

    /**
     * Display embedded Koha interface
     *
     * @param string $path
     * @param mixed $page
     * @return array
     */
    public function get_listing($url='', $page=0) {
        $records = $this->koha_listing($url, $page);
        $x = $this->koha_get_record_list($records, $page);
        return $x;
        var_dump($x);die();
    }

    public function search($terms, $page=0) {
        $records = $this->koha_search($terms, $page);
        return $this->koha_get_record_list($records, $page);
    }

    /**
     * Settings for the Koha plugin.
     */
    public static function get_type_option_names() {
        return array('url', 'page_limit', 'pluginname');
    }

    /**
     * Configuration form.
     */
    public static function type_config_form($mform, $classname='repository') {
        global $CFG;
        parent::type_config_form($mform);
        $url        = get_config('koha', 'url');
        $page_limit = get_config('koha', 'page_limit');

        if (empty($url)) {
            $url = '';
        }
        if (empty($page_limit)) {
            $page_limit = '10';
        }

        $mform->addElement('text', 'url', get_string('url', 'repository_koha'), array('value'=>$url, 'size' => '100'));
        $mform->setType('url', PARAM_URL);
        $mform->addRule('url', get_string('required'), 'required', null, 'client');
        $mform->addElement('text', 'page_limit', get_string('page_limit', 'repository_koha'), array('value'=>$page_limit, 'size' => '10'));
        $mform->addRule('page_limit', get_string('required'), 'required', null, 'client');
        $mform->setType('page_limit', PARAM_INT);
    }

    /**
     * Given a search string, this function returns result records from Koha.
     * Open Search Description can be found at
     * {$url}opac-search.pl?format=opensearchdescription
     * @param string $term The string to be searched.
     * @return Array An array of Koha records, as SimpleXMLElement objects
     */
    private function koha_search($terms='', $page=0) {
        $terms = str_replace(' ', '+', $terms);
        $url = "{$this->config->url}opac-search.pl?q={$terms}&format=rss2";
        return $this->koha_get_xmlfeed_items($url, $page);
    }

    /**
     * TODO: browse by subject headings - limit by mc-ccode or similar.
     */
    private function koha_listing($heading='', $page=0) {
        if (empty($heading)) {
            $url = "root list of headings";
        } else {        
            $url = "limit by $heading";
        }
        // TODO: search for nothing, for now. Returns zero items
        return $this->koha_search('', $page);
    }

    /**
     * Returns paginated records from the Koha search RSS feed.
     * @param string $url The Koha community, collection or search URL
     * @return Array An array of Koha records, as SimpleXMLElement objects
     */
    private function koha_get_xmlfeed_items($url, $page=0) {
        $url = "{$url}&pw={$page}";
        $xml = download_file_content($url);
        $sx = new SimpleXMLElement($xml);
        $sx->registerXPathNamespace('opensearch', 'http://a9.com/-/spec/opensearch/1.1/');
        $sx->registerXPathNamespace('dc',         'http://purl.org/dc/elements/1.1/');
        $sx->registerXPathNamespace('atom',       'http://www.w3.org/2005/Atom');

        // get pages and items per page
        $xp = $sx->xpath('/rss/channel/opensearch:totalResults');
        $this->total = !empty($xp) ? (string) $xp[0][0] : 0;
        $xp = $sx->xpath('/rss/channel/opensearch:itemsPerPage');
        $this->page_limit = !empty($xp) ? (string) $xp[0][0] : 0;

        $records = $sx->xpath("/rss/channel/item");
        return $records;
    }

    private function koha_get_record_list($records, $page=0) {
        global $OUTPUT;

        $results = array();
        $results['nologin'] = true;
        $results['dynload'] = true;
        $results['list'] = array();
        foreach($records as $record) {
            preg_match('/biblionumber=(\d+)/', (string) $record->link, $match);
            $id = $match[1];
            $results['list'][] = $this->koha_get_record($id);
        }
        return $results;
    }

    private function koha_get_record($id) {
        global $OUTPUT;
        $xml = download_file_content("{$this->config->url}opac-export.pl?bib={$id}&op=export&format=marcxml");
        $record = new SimpleXMLElement($xml);
        $record->registerXPathNamespace('n', 'http://www.loc.gov/MARC21/slim');
        $result = array();

        $xp = $record->xpath("//n:datafield[@tag='100']/n:subfield[@code='a']");
        !empty($xp) ? $result['author'] = (string) $xp[0][0]     : $result['author'] = '';
        $xp = $record->xpath("//n:datafield[@tag='245']/n:subfield[@code='a']");
        !empty($xp) ? $result['title'] = (string) $xp[0][0]      : $result['title'] = '';
        #$xp = $record->xpath("//n:datafield[@tag='260']/n:subfield[@code='c']");
        #!empty($xp) ? $year = (string) $xp[0][0] : $year = '';
        #$date = "$year-01-01T00:00:00Z";die($year);
        $result['datecreated'] = '';

        $result['koha_id']          = $id;
        $result['source']           = "{$this->config->url}opac-detail.pl?biblionumber={$id}";
        $result['url']              = $result['source'];
        $result['shorttitle']       = $result['title'];
        $result['size']             = '';
        $result['icon']             = $OUTPUT->pix_url('icon', 'repository_koha')->out(false);
        $result['thumbnail']        = $OUTPUT->pix_url('icon', 'repository_koha')->out(false);
        $result['datemodified']     = $result['datecreated'];
        $result['thumbnail_width']  = 90;
        $result['thumbnail_height'] = 90;

        // Figure out the lending status
        $xp = $record->xpath("//n:datafield[@tag='952']/n:subfield[@code='0']");
        !empty($xp) ? $withdrawn = (string) $xp[0][0]  : $withdrawn = 0;
        $xp = $record->xpath("//n:datafield[@tag='952']/n:subfield[@code='1']");
        !empty($xp) ? $lost = (string) $xp[0][0]       : $lost = 0;
        $xp = $record->xpath("//n:datafield[@tag='952']/n:subfield[@code='4']");
        !empty($xp) ? $damaged = (string) $xp[0][0]    : $damaged = 0;
        $xp = $record->xpath("//n:datafield[@tag='952']/n:subfield[@code='q']");
        !empty($xp) ? $duedate = (string) $xp[0][0]    : $duedate = '';
        if (!empty($duedate)) {
            $result['lendingstatus'] = "Lending status: Due {$duedate}.";
        } else {
            $result['lendingstatus'] = "Lending status: Currently available.";
        }
        if ($withdrawn == 1) {
            $result['lendingstatus'] = 'Lending status: Unavailable - withdrawn.';
        }
        if ($lost > 0) {
            $result['lendingstatus'] = 'Lending status: Unavailable - lost.';
        }
        if ($damaged == 1) {
            $result['lendingstatus'] = 'Lending status: Unavailable - damaged.';
        }

        // Get a citation snippet and add as the description (for now)
        $result['koha_citation'] = $this->koha_format_citation($result);
        $result['description'] = $result['koha_citation'];

        return $result;
    }

    private function koha_format_citation($record, $striplinks=true) {

        // Assemble a record view snippet
        $citation = "<div class=\"koharecord\">
            <a href=\"opac-search.pl?q=au:{$record['author']}\">{$record['author']}</a>.
            <a href=\"{$this->config->url}opac-detail.pl?biblionumber={$record['koha_id']}\">\"{$record['title']}\"</a>. {$record['datecreated']}
            <br />
            <em class=\"koharecord_lendingstatus\">{$record['lendingstatus']}</em>
            </div>
        ";

        if ($striplinks) {
            $citation = strip_links($citation);
        }

        return $citation;
    }
}
