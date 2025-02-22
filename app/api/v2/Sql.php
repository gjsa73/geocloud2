<?php
/**
 * @author     Martin Høgh <mh@mapcentia.com>
 * @copyright  2013-2019 MapCentia ApS
 * @license    http://www.gnu.org/licenses/#AGPL  GNU AFFERO GENERAL PUBLIC LICENSE 3
 *
 */

namespace app\api\v2;

use app\inc\Input;

/**
 * Class Sql
 * @package app\api\v1
 */
class Sql extends \app\inc\Controller
{
    /**
     * @var array
     */
    public $response;

    /**
     * @var string
     */
    private $q;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var array
     */
    private $data;

    /**
     * @var string
     */
    private $subUser;

    /**
     * @var \app\models\Sql
     */
    private $api;

    /**
     * @var array
     */
    private $usedRelations;

    /**
     *
     */
    const USEDRELSKEY = "checked_relations";

    /**
     * @var \Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface
     */
    private $InstanceCache;

    /**
     * @var
     */
    private $cacheInfo;

    /**
     * @var
     */
    private $streamFlag;

    function __construct()
    {
        global $globalInstanceCache;

        parent::__construct();

        $this->InstanceCache = $globalInstanceCache;
    }

    /**
     * @return array
     */
    public function get_index(): array
    {
        // Get the URI params from request
        // /{user}
        $r = func_get_arg(0);

        if (isset($r["method"]) && $r["method"] == "stream") {
            $this->streamFlag = true;

        }

        $db = $r["user"];
        $dbSplit = explode("@", $db);

        if (sizeof($dbSplit) == 2) {
            $this->subUser = $dbSplit[0];
        } elseif (!empty($_SESSION["subuser"])) {
            $this->subUser = $_SESSION["screen_name"];
        } else {
            $this->subUser = null;
        }

        // Check if body is JSON
        // Supports both GET and POST
        // ==========================
        $json = json_decode(Input::getBody(), true);

        // If JSON body when set GET input params
        // ======================================
        if ($json != null) {

            // Set input params from JSON
            // ==========================
            Input::setParams(
                [
                    "q" => !empty($json["q"]) ? $json["q"] : null,
                    "client_encoding" => !empty($json["client_encoding"]) ? $json["client_encoding"] : null,
                    "srs" => !empty($json["srs"]) ? $json["srs"] : null,
                    "format" => !empty($json["format"]) ? $json["format"] : null,
                    "geoformat" => !empty($json["geoformat"]) ? $json["geoformat"] : null,
                    "key" => !empty($json["key"]) ? $json["key"] : null,
                    "geojson" => !empty($json["geojson"]) ? $json["geojson"] : null,
                    "allstr" => !empty($json["allstr"]) ? $json["allstr"] : null,
                    "alias" => !empty($json["alias"]) ? $json["alias"] : null,
                    "lifetime" => !empty($json["lifetime"]) ? $json["lifetime"] : null,
                    "base64" => !empty($json["base64"]) ? $json["base64"] : null,
                ]
            );
        }

        if (Input::get('base64') === true || Input::get('base64') === "true") {
            $this->q = base64_decode(Input::get('q'));
        } else {
            $this->q = urldecode(Input::get('q'));
        }

        if (!$this->q) {
            $response['success'] = false;
            $response['code'] = 403;
            $response['message'] = "Query is missing (the 'q' parameter)";
            return $response;
        }

        $settings_viewer = new \app\models\Setting();
        $res = $settings_viewer->get();

        // Check if success
        // ================
        if (!$res["success"]) {
            return $res;
        }

        $srs = Input::get('srs') ?: "900913";
        $this->api = new \app\models\Sql($srs);
        $this->api->connect();
        $this->apiKey = $res['data']->api_key;

        $this->response = $this->transaction($this->q, Input::get('client_encoding'));

        // Check if $this->data is set in SELECT section
        if (!$this->data) {
            $this->data = $this->response;
        }
        $response = unserialize($this->data);
        if ($this->cacheInfo) {
            $response["cache_hit"] = $this->cacheInfo;
        }
        $response["peak_memory_usage"] = round(memory_get_peak_usage() / 1024) . " KB";

        return $response;
    }

    /**
     * @return array
     */
    public function post_index(): array
    {
        // Use bulk if content type is text/plain
        if (Input::getContentType() == Input::TEXT_PLAIN) {

            // Set API key from headers
            Input::setParams(
                [
                    "key" => Input::getApiKey()
                ]
            );

            $settings_viewer = new \app\models\Setting();
            $res = $settings_viewer->get();

            // Check if success
            // ================
            if (!$res["success"]) {
                return $res;
            }

            $this->api = new \app\models\Sql();
            $this->api->connect();
            $this->apiKey = $res['data']->api_key;

            $sqls = explode("\n", Input::getBody());
            $this->api->begin(); // Start transaction
            foreach ($sqls as $q) {
                $this->q = $q;
                if ($this->q != "") {
                    $res = unserialize($this->transaction($this->q));
                    if (!$res["success"]) {
                        $this->api->rollback();
                        return $res;
                    }
                }
            }
            $this->api->commit();
            return $res;

        } else {
            return $this->get_index(func_get_arg(0));
        }
    }

    /**
     * @return array
     */
    public function get_stream()
    {
        $this->streamFlag = true;
        return $this->get_index(func_get_arg(0));
    }

    /**
     * @param array $array
     * @param string $needle
     * @return array
     */
    private function recursiveFind(array $array, string $needle): array
    {
        $iterator = new \RecursiveArrayIterator($array);
        $recursive = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);
        $aHitList = [];
        foreach ($recursive as $key => $value) {
            if ($key === $needle) {
                array_push($aHitList, $value);
            }
        }
        return $aHitList;
    }

    /**
     * @param array $fromArr
     */
    private function parseSelect(array $fromArr = null)
    {
        if (is_array($fromArr)) {
            foreach ($fromArr as $table) {
                if ($table["expr_type"] == "subquery") {

                    // Recursive call
                    $this->parseSelect($table["sub_tree"]["FROM"]);
                }
                $table["no_quotes"] = str_replace('"', '', $table["no_quotes"]);
                if (
                    explode(".", $table["no_quotes"])[0] == "settings" ||
                    explode(".", $table["no_quotes"])[0] == "information_schema" ||
                    explode(".", $table["no_quotes"])[0] == "sqlapi" ||
                    explode(".", $table["no_quotes"])[1] == "geometry_columns" ||
                    $table["no_quotes"] == "geometry_columns"
                ) {
                    $this->response['success'] = false;
                    $this->response['message'] = "Can't complete the query";
                    $this->response['code'] = 403;
                    die(\app\inc\Response::toJson($this->response));
                }
                if ($table["no_quotes"]) {
                    $this->usedRelations[] = $table["no_quotes"];
                }
                $this->usedRelations = array_unique($this->usedRelations);
            }
        }
    }

    /**
     * @param string $sql
     * @param string|null $clientEncoding
     * @return string
     */
    private function transaction(string $sql, string $clientEncoding = null)
    {
        $response = [];
        if (strpos($sql, ';') !== false) {
            $this->response['success'] = false;
            $this->response['code'] = 403;
            $this->response['message'] = "You can't use ';'. Use the bulk transaction API instead";
            return serialize($this->response);
        }
        if (strpos($sql, '--') !== false) {
            $this->response['success'] = false;
            $this->response['code'] = 403;
            $this->response['message'] = "SQL comments '--' are not allowed";
            return serialize($this->response);
        }
        require_once dirname(__FILE__) . '/../../libs/PHP-SQL-Parser/src/PHPSQLParser.php';
        try {
            $parser = new \PHPSQLParser($sql, false);
        } catch (\UnableToCalculatePositionException $e) {
            $this->response['success'] = false;
            $this->response['code'] = 403;
            $this->response['message'] = $e->getMessage();
            return serialize($this->response);
        }
        $parsedSQL = $parser->parsed ?: []; // Make its an array
        $this->usedRelations = array();

        // First recursive go through the SQL to find FROM in select, update and delete
        foreach ($this->recursiveFind($parsedSQL, "FROM") as $x) {
            $this->parseSelect($x);
        }

        // Check auth on relations
        foreach ($this->usedRelations as $rel) {
            $response = $this->ApiKeyAuthLayer($rel, $this->subUser, false, Input::get('key'), $this->usedRelations);
            if (!$response["success"]) {
                return serialize($response);
            }
        }

        // Check SQL UPDATE
        if (isset($parsedSQL['UPDATE'])) {
            foreach ($parsedSQL['UPDATE'] as $table) {
                if (
                    explode(".", $table["no_quotes"])[0] == "settings" ||
                    explode(".", $table["no_quotes"])[0] == "information_schema" ||
                    explode(".", $table["no_quotes"])[0] == "sqlapi" ||
                    explode(".", $table["no_quotes"])[1] == "geometry_columns" ||
                    $table["no_quotes"] == "geometry_columns"
                ) {
                    $this->response['success'] = false;
                    $this->response['message'] = "Can't complete the query";
                    $this->response['code'] = 406;
                    return serialize($this->response);
                }
                array_push($this->usedRelations, $table["no_quotes"]);
                $response = $this->ApiKeyAuthLayer($table["no_quotes"], $this->subUser, true, Input::get('key'), $this->usedRelations);
                if (!$response["success"]) {
                    return serialize($response);
                }
            }

            // Check SQL DELETE
        } elseif (isset($parsedSQL['DELETE'])) {
            foreach ($parsedSQL['FROM'] as $table) {
                if (
                    explode(".", $table["no_quotes"])[0] == "settings" ||
                    explode(".", $table["no_quotes"])[0] == "information_schema" ||
                    explode(".", $table["no_quotes"])[0] == "sqlapi" ||
                    explode(".", $table["no_quotes"])[1] == "geometry_columns" ||
                    $table["no_quotes"] == "geometry_columns"
                ) {
                    $this->response['success'] = false;
                    $this->response['message'] = "Can't complete the query";
                    $this->response['code'] = 406;
                    return serialize($this->response);
                }
                $response = $this->ApiKeyAuthLayer($table["no_quotes"], $this->subUser, true, Input::get('key'), $this->usedRelations);
                if (!$response["success"]) {
                    return serialize($response);
                }
            }

            // Check SQL INSERT
        } elseif (isset($parsedSQL['INSERT'])) {
            foreach ($parsedSQL['INSERT'] as $table) {
                if (
                    explode(".", $table["no_quotes"])[0] == "settings" ||
                    explode(".", $table["no_quotes"])[0] == "information_schema" ||
                    explode(".", $table["no_quotes"])[0] == "sqlapi" ||
                    explode(".", $table["no_quotes"])[1] == "geometry_columns" ||
                    $table["no_quotes"] == "geometry_columns"
                ) {
                    $this->response['success'] = false;
                    $this->response['message'] = "Can't complete the query";
                    $this->response['code'] = 406;
                    return serialize($this->response);
                }
                array_push($this->usedRelations, $table["no_quotes"]);
                $response = $this->ApiKeyAuthLayer($table["no_quotes"], $this->subUser, true, Input::get('key'), $this->usedRelations);
                if (!$response["success"]) {
                    return serialize($response);
                }
            }
        }

        if (!empty($parsedSQL['DROP'])) {
            $this->response['success'] = false;
            $this->response['code'] = 403;
            $this->response['message'] = "DROP is not allowed through the API";
        } elseif (!empty($parsedSQL['ALTER'])) {
            $this->response['success'] = false;
            $this->response['code'] = 403;
            $this->response['message'] = "ALTER is not allowed through the API";
        } elseif (!empty($parsedSQL['CREATE'])) {
            if (!empty($parsedSQL['CREATE']) && (!empty($parsedSQL['VIEW']) || !empty($parsedSQL['TABLE']))) {
                if ($this->apiKey == Input::get('key') && $this->apiKey != false) {
                    $this->response = $this->api->transaction($this->q);
                    $this->addAttr($response);
                } else {
                    $this->response['success'] = false;
                    $this->response['message'] = "Not the right key!";
                    $this->response['code'] = 403;
                }
            } else {
                $this->response['success'] = false;
                $this->response['message'] = "Only CREATE VIEW is allowed through the API";
                $this->response['code'] = 403;
            }
        } elseif (!empty($parsedSQL['UPDATE']) || !empty($parsedSQL['INSERT']) || !empty($parsedSQL['DELETE'])) {
            $this->response = $this->api->transaction($this->q);
            $this->addAttr($response);
        } elseif (!empty($parsedSQL['SELECT']) || !empty($parsedSQL['UNION'])) {
            if ($this->streamFlag) {
                $stream = new \app\models\Stream();
                $res = $stream->runSql($this->q);
                return ($res);
            }

            $lifetime = (Input::get('lifetime')) ?: 0;

            $cacheId = md5($this->q . "_" . $lifetime);

            if ($lifetime > 0) {
                try {
                    $CachedString = $this->InstanceCache->getItem($cacheId);
                } catch (\Exception $e) {
                    $CachedString = null;
                }
            }

            if ($lifetime > 0 && !empty($CachedString) && $CachedString->isHit()) {
                $this->data = $CachedString->get();
                try {
                    $CreationDate = $CachedString->getCreationDate();
                } catch (\Exception $e) {
                    $CreationDate = $e->getMessage();
                }
                $this->cacheInfo["cache_hit"] = $CreationDate;
                $this->cacheInfo["cache_signature"] = md5(serialize($this->data));

            } else {
                ob_start();

                $format = Input::get('format') ?: "geojson";
                if (!in_array($format, ["geojson", "csv", "excel"])) {
                    die("{$format} is not a supported format.");
                }

                $geoformat = Input::get('geoformat') ?: null;
                if (!in_array($geoformat, [null, "geojson", "wkt"])) {
                    die("{$geoformat} is not a supported geom format.");
                }
                $csvAllToStr = Input::get('allstr') ?: null;

                $alias = Input::get('alias') ?: null;


                $this->response = $this->api->sql($this->q, $clientEncoding, $format, $geoformat, $csvAllToStr, $alias);
                $this->addAttr($response);

                echo serialize($this->response);
                $this->data = ob_get_contents();
                if ($lifetime > 0) {
                    $CachedString->set($this->data)->expiresAfter($lifetime ?: 1);// Because 0 secs means cache will life for ever, we set cache to one sec
                    $this->InstanceCache->save($CachedString); // Save the cache item just like you do with doctrine and entities
                    $this->cacheInfo["cache_hit"] = false;
                }
                ob_get_clean();
            }
        } else {
            $this->response['success'] = false;
            $this->response['message'] = "Check your SQL. Could not recognise it as either SELECT, INSERT, UPDATE or DELETE";
            $this->response['code'] = 400;
        }
        return serialize($this->response);
    }

    /**
     * @param $arr
     */
    private function addAttr(array $arr)
    {
        foreach ($arr as $key => $value) {
            if ($key != "code") {
                $this->response["auth_check"][$key] = $value;
            }
        }

    }
}