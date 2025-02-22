<?php
/**
 * @author     Martin Høgh <mh@mapcentia.com>
 * @copyright  2013-2019 MapCentia ApS
 * @license    http://www.gnu.org/licenses/#AGPL  GNU AFFERO GENERAL PUBLIC LICENSE 3
 *
 */

namespace app\models;

use \app\conf\App;
use app\inc\Model;

define('VDAEMON_PARSE', false);
define('VD_E_POST_SECURITY', false);
require(__DIR__ . '/../../public/user/vdaemon/vdaemon.php');

/**
 * Class User
 * @package app\models
 */
class User extends Model
{
    public $userId;
    public $parentdb;

    function __construct($userId = null, $parentdb = null)
    {
        parent::__construct();
        $this->userId = $userId;
        $this->parentdb = $parentdb;
        $this->postgisdb = "mapcentia";
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getAll(): array
    {
        $query = "SELECT * FROM users WHERE email<>''";
        $res = $this->execQuery($query);
        $rows = $this->fetchAll($res);
        if (!$this->PDOerror) {
            $response['success'] = true;
            $response['data'] = $rows;
        } else {
            $response['success'] = false;
            $response['message'] = $this->PDOerror;
        }
        return $response;
    }

    /**
     * @return array
     */
    public function getDatabasesForUser($userIdentifier): array
    {
        if (empty($userIdentifier)) {
            throw new Exception('User name or email should not be empty');
        }

        $data = [];
        if (strrpos($userIdentifier, '@') === false) {
            $userName = Model::toAscii($userIdentifier, NULL, "_");

            $query = "SELECT screenname, email, parentdb FROM users WHERE screenname = :sUserID";
            $res = $this->prepare($query);
            $res->execute(array(":sUserID" => $userName));

            while ($row = $this->fetchRow($res, "assoc")) {
                array_push($data, $row);
            }
        } else {
            $query = "SELECT screenname, email, parentdb FROM users WHERE email = :sUserEmail";
            $res = $this->prepare($query);
            $res->execute(array(":sUserEmail" => $userIdentifier));

            while ($row = $this->fetchRow($res, "assoc")) {
                array_push($data, $row);
            }
        }

        return [
            'databases' => $data,
        ];
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        $query = "SELECT email, parentdb, usergroup, screenname as userid, zone FROM users WHERE screenname = :sUserID AND (parentdb = :parentDb OR parentDB IS NULL)";
        $res = $this->prepare($query);
        $res->execute(array(":sUserID" => $this->userId, ":parentDb" => $this->parentdb));
        $row = $this->fetchRow($res);
        if (!$row['userid']) {
            $response['success'] = false;
            $response['message'] = "User identifier $this->userId was not found (parent database: " . ($this->parentdb ? $this->parentdb : 'null') . ")";
            $response['code'] = 404;
            return $response;
        }
        if (!$this->PDOerror) {
            $response['success'] = true;
            $response['data'] = $row;
        } else {
            $response['success'] = false;
            $response['message'] = $this->PDOerror;
        }
        return $response;
    }

    /**
     * @param array $data
     * @return array
     */
    public function createUser(array $data): array
    {
        $mandatoryParameters = ['name', 'email', 'password'];
        foreach ($mandatoryParameters as $item) {
            if (empty($data[$item])) {
                return array(
                    'code' => 400,
                    'success' => false,
                    'message' => "$item has to be provided"
                );
            }
        }

        $name = VDFormat($data['name'], true);
        $email = VDFormat($data['email'], true);
        $password = VDFormat($data['password'], true);
        $group = (empty($data['usergroup']) ? null : VDFormat($data['usergroup'], true));
        $zone = (empty($data['zone']) ? null : VDFormat($data['zone'], true));

        // Generate user identifier from the name
        $userId = Model::toAscii($name, NULL, "_");

        // Check if such user identifier already exists
        $res = $this->execQuery("SELECT COUNT(*) AS count FROM users WHERE screenname = '$userId'");
        $result = $this->fetchRow($res);
        if ($result['count'] > 0) {
            if ($data['subuser']) {
                $res = $this->execQuery("SELECT COUNT(*) AS count FROM users WHERE screenname = '" . $userId . "' AND parentdb = '" . $this->userId . "'");
                $result = $this->fetchRow($res);
                if ($result['count'] > 0) {
                    return array(
                        'code' => 400,
                        'success' => false,
                        'errorCode' => 'SUB_USER_ALREADY_EXISTS',
                        'message' => "User identifier $userId already exists for parent database " . $this->userId
                    );
                }
                $res = $this->execQuery("SELECT COUNT(*) AS count FROM users WHERE screenname = '" . $userId . "' AND parentdb ISNULL");
                $result = $this->fetchRow($res);
                if ($result['count'] > 0) {
                    return array(
                        'code' => 400,
                        'success' => false,
                        'errorCode' => 'PARENT_USER_EXISTS_WITH_NAME',
                        'message' => "User identifier $userId already exists as database"
                    );
                }

            } else {
                return array(
                    'code' => 400,
                    'success' => false,
                    'errorCode' => 'USER_ALREADY_EXISTS',
                    'message' => "User identifier $userId already exists"
                );
            }
        }

        // Check if such email already exists in the database - there can not be two super-user with the same email,
        // but there can be tow sub-users with the same email in different databases
        $sql = false;
        if (empty($this->userId)) {
            $sql = "SELECT COUNT(*) AS count FROM users WHERE email = '$email'";
        } else {
            $sql = "SELECT COUNT(*) AS count FROM users WHERE email = '$email' AND parentdb = '" . $this->userId . "'";
        }

        $res = $this->execQuery($sql);
        $result = $this->fetchRow($res);
        if ($result['count'] > 0) {
            return array(
                'code' => 400,
                'success' => false,
                'errorCode' => 'EMAIL_ALREADY_EXISTS',
                'message' => "Email $email already exists"
            );
        }

        // Check if the password is strong enough
        $passwordCheckResults = Setting::checkPasswordStrength($password);
        if (sizeof($passwordCheckResults) > 0) {
            return array(
                'code' => 400,
                'success' => false,
                'errorCode' => 'WEAK_PASSWORD',
                'message' => 'Password does not meet following requirements: ' . implode(', ', $passwordCheckResults)
            );
        }

        $encryptedPassword = Setting::encryptPwSecure($password);

        // Create new database
        if ($data['subuser'] === false) {
            $db = new Database();
            $db->postgisdb = $this->postgisdb;
            try {
                $db->createdb($userId, App::$param['databaseTemplate'], "UTF8");
            } catch (\Exception $e) {
                // PASS
            }
        }

        $sQuery = "INSERT INTO users (screenname,pw,email,parentdb,usergroup,zone) VALUES(:sUserID, :sPassword, :sEmail, :sParentDb, :sUsergroup, :zone) RETURNING screenname,parentdb,email,usergroup,zone";

        try {
            $res = $this->prepare($sQuery);
            $res->execute(array(
                ":sUserID" => $userId,
                ":sPassword" => $encryptedPassword,
                ":sEmail" => $email,
                ":sParentDb" => $this->userId,
                ":sUsergroup" => $group,
                ":zone" => $zone
            ));

            $row = $this->fetchRow($res, "assoc");
        } catch (\Exception $e) {
            $response['success'] = false;
            $response['message'] = $e->getMessage();
            $response['test'] = \app\inc\Session::getUser();
            $response['code'] = 400;
            return $response;
        }

        $response['success'] = true;
        $response['message'] = 'User was created';
        $response['data'] = $row;
        return $response;
    }

    /**
     * @param array $data
     * @return array
     */
    public function updateUser(array $data): array
    {
        $user = isset($data["user"]) ? Model::toAscii($data["user"], NULL, "_") : null;

        // Check if such email already exists
        $email = null;
        if (isset($data["email"])) {
            $res = $this->execQuery("SELECT COUNT(*) AS count FROM users WHERE email = '" . $data["email"] . "'");
            $result = $this->fetchRow($res);
            if ($result['count'] > 0) {
                return array(
                    'code' => 400,
                    'success' => false,
                    'message' => "Email " . $data["email"] . " already exists"
                );
            }

            $email = $data["email"];
        }

        // Check if the password is strong enough
        $password = null;
        if (isset($data["password"])) {
            $passwordCheckResults = Setting::checkPasswordStrength($data["password"]);
            if (sizeof($passwordCheckResults) > 0) {
                return array(
                    'code' => 400,
                    'success' => false,
                    'message' => 'Password does not meet following requirements: ' . implode(', ', $passwordCheckResults)
                );
            }

            $password = Setting::encryptPwSecure($data["password"]);
        }

        $userGroup = isset($data["usergroup"]) ? $data["usergroup"] : null;

        $sQuery = "UPDATE users SET screenname=screenname";
        if ($password) $sQuery .= ", pw=:sPassword";
        if ($email) $sQuery .= ", email=:sEmail";
        if ($userGroup) {
            $sQuery .= ", usergroup=:sUsergroup";
            $obj[$user] = $userGroup;

            Database::setDb($this->parentdb);
            $settings = new \app\models\Setting();
            if (!$settings->updateUserGroups((object)$obj)['success']) {
                $response['success'] = false;
                $response['message'] = "Could not update settings.";
                $response['code'] = 400;
                return $response;
            };
            Database::setDb("mapcentia");

        }

        if (!empty($data["parentdb"])) {
            $sQuery .= " WHERE screenname=:sUserID AND parentdb=:sParentDb RETURNING screenname,email,usergroup";
        } else {
            $sQuery .= " WHERE screenname=:sUserID RETURNING screenname,email,usergroup";
        }

        try {
            $res = $this->prepare($sQuery);
            if ($password) $res->bindParam(":sPassword", $password);
            if ($email) $res->bindParam(":sEmail", $email);
            if ($userGroup) $res->bindParam(":sUsergroup", $userGroup);
            $res->bindParam(":sUserID", $user);
            if (!empty($data["parentdb"])) $res->bindParam(":sParentDb", $data["parentdb"]);


            $res->execute();
            $row = $this->fetchRow($res, "assoc");
        } catch (\Exception $e) {
            $response['success'] = false;
            $response['message'] = $e->getMessage();
            $response['test'] = \app\inc\Session::getUser();
            $response['code'] = 400;
            return $response;
        }

        $response['success'] = true;
        $response['message'] = "User was updated";
        $response['data'] = $row;
        return $response;
    }

    /**
     * @param string $data
     * @return array
     */
    public function deleteUser(string $data): array
    {
        $user = $data ? Model::toAscii($data, NULL, "_") : null;
        $sQuery = "DELETE FROM users WHERE screenname=:sUserID AND parentdb=:parentDb";
        try {
            $res = $this->prepare($sQuery);
            $res->execute([":sUserID" => $user, ":parentDb" => $this->userId]);
        } catch (\Exception $e) {
            $response['success'] = false;
            $response['message'] = $e->getMessage();
            $response['test'] = \app\inc\Session::getUser();
            $response['code'] = 400;
            return $response;
        }

        $response['success'] = true;
        $response['message'] = "User was deleted";
        $response['data'] = $res->rowCount();
        return $response;
    }

    /**
     * @param string $userId
     * @return bool
     */
    public function getSubusers(string $userId): array
    {
        $sQuery = "SELECT * FROM users WHERE parentdb = :sUserID";
        $res = $this->prepare($sQuery);
        $res->execute([":sUserID" => $userId]);

        $subusers = [];
        while ($row = $this->fetchRow($res, "assoc")) {
            $row["screenName"] = $row["screenname"];
            unset($row["screenname"]);
            unset($row["pw"]);
            array_push($subusers, $row);
        }

        $response = [];
        $response['success'] = true;
        $response['data'] = $subusers;

        return $response;
    }

    /**
     * @param string $userId
     * @param string $password
     * @return bool
     */
    public function hasPassword(string $userId, string $checkedPassword): bool
    {
        $sQuery = "SELECT pw FROM users WHERE screenname = :sUserID";
        $res = $this->prepare($sQuery);
        $res->execute([":sUserID" => $userId]);
        $row = $this->fetchRow($res, "assoc");

        $hasPassword = false;
        if (md5($checkedPassword) === $row['pw']) {
            $hasPassword = true;
        } else if (password_verify($checkedPassword, $row['pw'])) {
            $hasPassword = true;
        }

        return $hasPassword;
    }
}