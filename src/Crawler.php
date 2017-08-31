<?php

namespace BilginPro\Agency\Aa;

use BilginPro\Agency\Aa\Exceptions\AuthenticationException;
use BilginPro\Agency\Aa\Exceptions\NoDataFoundException;
use Carbon\Carbon;
use GuzzleHttp;

/**
 * Class Crawler
 * @package BilginPro\Ajans\Aa
 */
class Crawler
{
    /**
     * Base URL of AA API
     */
    const API_BASE_URL = 'https://api.aa.com.tr';

    /**
     * @var string
     */
    protected $userName = '';

    /**
     * @var string
     */
    protected $password = '';

    /**
     * @var int
     */
    protected $summaryLength = 150;

    /**
     * @var array
     */
    protected $attributes = [
        'filter_language' => '1',
        'filter_type' => '1',
        'limit' => '5',
    ];

    /**
     * @var array
     */
    protected $auth = ['', ''];

    /**
     * Create a new Crawler Instance
     */
    public function __construct($config)
    {
        $this->setParameters($config);
    }

    /**
     * @param array $attributes
     * @return array
     */
    public function crawl($attributes = [])
    {
        $this->setAttributes($attributes);

        $result = [];

        $search = $this->search();

        usleep(300000);
        foreach ($search->data->result as $item) {
            $newsml = $this->document($item->id);
            if (!empty($newsml)) {
                $news = $this->newsmlToNews($newsml);
                $result[] = $news;
            }
            usleep(300000);
        }
        return $result;
    }


    /**
     * Creates a news object from NewsML SimpleXmlElement instance.
     * @param $xml \SimpleXMLElement
     * @return \stdClass
     */
    public function newsmlToNews($xml)
    {
        $news = new \stdClass();
        $xml->registerXPathNamespace("n", "http://iptc.org/std/nar/2006-10-01/");
        $news->title = (string)$xml->itemSet->newsItem->contentMeta->headline;
        $news->summary = (string)$xml->itemSet->newsItem->contentSet->inlineXML->nitf->body->{'body.head'}->abstract;
        $news->content = (string)$xml->itemSet->newsItem->contentSet->inlineXML->nitf->body->{'body.content'};
        $news->created_at = (new Carbon($xml->itemSet->newsItem->itemMeta->versionCreated))
            ->addHours(3)->format('d.m.Y H:i:s');
        $news->category = (string)$xml->xpath('//n:subject/n:name[@xml:lang="tr"]')[0];
        $news->city = '';
        if (isset($xml->xpath('//n:contentMeta/n:located[@type="cptype:city"]/n:name[@xml:lang="tr"]')[0])) {
            $news->city = (string)$xml
                ->xpath('//n:contentMeta/n:located[@type="cptype:city"]/n:name[@xml:lang="tr"]')[0];
        }
        $news->images = [];
        if (isset($xml->xpath('//n:newsItem/n:itemMeta/n:link[@rel="irel:seeAlso"]')[0]['residref'])) {
            $picture_id = (string)$xml->xpath('//n:newsItem/n:itemMeta/n:link[@rel="irel:seeAlso"]')[0]['residref'];
            $news->images[] = $this->getDocumentLink($picture_id, 'web');
        }
        return $news;
    }

    /**
     * Creates document link for next requests.
     * @param $id string
     * @param $format string
     * @return string
     */
    public function getDocumentLink($id, $format)
    {
        return self::API_BASE_URL . '/document/' . $id . '/' . $format;
    }

    /**
     * Fetches NewsML document, creates a SimpleXMLElement instance and returns it.
     * @param $id
     * @return null|\SimpleXMLElement
     */
    public function document($id)
    {
        $xml = null;
        $url = self::API_BASE_URL . '/abone/document/' . $id . '/newsml29?v=2' . rand(1000, 9999);
        $newsml = $this->fetchUrl($url, 'GET', ['auth' => $this->auth]);
        $xml = simplexml_load_string($newsml);
        return $xml;
    }

    /**
     * Searchs documents with given filter attributes.
     * @return mixed
     */
    public function search()
    {
        $res = $this->fetchUrl(self::API_BASE_URL . '/abone/search', 'POST', [
            'auth' => $this->auth,
            'form_params' => $this->attributes
        ]);

        $search = json_decode($res);

        switch ($search->response->code) {
            case 200:
                break;
            case 401:
                throw new AuthenticationException;
            default:
                throw new NoDataFoundException;
        }
        return $search;
    }

    /**
     * Creates short summary of the news, strip credits.
     * @param string $text
     * @return string
     */
    public function createSummary($text)
    {
        if (strpos($text, '(DHA)') > 0) {
            $split = explode('(DHA)', $text);
            if (count($split) > 1) {
                $text = $split[1];
                $text = trim($text, ' \t\n\r\0\x0B-');
            }
        }
        $summary = (string)$this->shortenString(strip_tags($text), $this->summaryLength);

        return $summary;
    }

    /**
     * Sets config parameters.
     * @param $config
     */
    public function setParameters($config)
    {
        if (!is_array($config)) {
            throw new \InvalidArgumentException('$config variable must be an array.');
        }
        if (array_key_exists('userName', $config)) {
            $this->userName = $config['userName'];
        }
        if (array_key_exists('password', $config)) {
            $this->password = $config['password'];
        }

        $this->auth = [$this->userName, $this->password];
    }

    /**
     * Sets filter attributes.
     * @param $attributes array
     */
    public function setAttributes($attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }


    /**
     * Fethches given url and returns response as string.
     * @param $url
     * @param string $method
     * @param array $options
     *
     * @return string
     */
    public function fetchUrl($url, $method = 'GET', $options = [])
    {
        $client = new GuzzleHttp\Client();
        $res = $client->request($method, $url, $options);
        if ($res->getStatusCode() == 200) {
            return (string)$res->getBody();
        }
        return '';
    }

    /**
     * Cuts the given string from the end of the appropriate word.
     * @param $str
     * @param $len
     * @return string
     */
    public function shortenString($str, $len)
    {
        if (strlen($str) > $len) {
            $str = rtrim(mb_substr($str, 0, $len, 'UTF-8'));
            $str = substr($str, 0, strrpos($str, ' '));
            $str .= '...';
            $str = str_replace(',...', '...', $str);
        }
        return $str;
    }

    /**
     * Converts a string to "Title Case"
     * @param $str
     * @return string
     */
    public function titleCase($str)
    {
        $str = mb_convert_case($str, MB_CASE_TITLE, 'UTF-8');
        return $str;
    }
}
