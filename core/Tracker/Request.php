<?php
use Piwik\Config;
use Piwik\Common;
use Piwik\Cookie;
use Piwik\IP;
use Piwik\Tracker;

/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

class Piwik_Tracker_Request
{
    /**
     * @var array
     */
    protected $params;

    public function __construct($params, $tokenAuth = false)
    {
        if(!is_array($params)) {
            $params = array();
        }
        $this->params = $params;
        $this->timestamp = time();
        $this->enforcedIp = false;

        // When the 'url' and referer url parameter are not given, we might be in the 'Simple Image Tracker' mode.
        // The URL can default to the Referer, which will be in this case
        // the URL of the page containing the Simple Image beacon
        if (empty($this->params['urlref'])
            && empty($this->params['url'])
        ) {
            $url = @$_SERVER['HTTP_REFERER'];
            if(!empty($url)) {
                $this->params['url'] = $url;
            }
        }
        $this->authenticateTrackingApi($tokenAuth);
    }

    protected $isAuthenticated = false;

    const UNKNOWN_RESOLUTION = 'unknown';

    public function isAuthenticated()
    {
        return $this->isAuthenticated;
    }

    /**
     * This method allows to set custom IP + server time + visitor ID, when using Tracking API.
     * These two attributes can be only set by the Super User (passing token_auth).
     */
    protected function authenticateTrackingApi($tokenAuthFromBulkRequest)
    {
        $shouldAuthenticate = Config::getInstance()->Tracker['tracking_requests_require_authentication'];
        if ($shouldAuthenticate) {
            $tokenAuth = $tokenAuthFromBulkRequest || Common::getRequestVar('token_auth', false, 'string', $this->params);
            try {
                $idSite = $this->getIdSite();
                $this->isAuthenticated = $this->authenticateSuperUserOrAdmin($tokenAuth, $idSite);
            } catch(Exception $e) {
                $this->isAuthenticated = false;
            }
            if (!$this->isAuthenticated) {
                return;
            }
            Common::printDebug("token_auth is authenticated!");
        } else {
            $this->isAuthenticated = true;
            Common::printDebug("token_auth authentication not required");
        }
    }

    public static function authenticateSuperUserOrAdmin($tokenAuth, $idSite)
    {
        if (!$tokenAuth) {
            return false;
        }
        $superUserLogin = Config::getInstance()->superuser['login'];
        $superUserPassword = Config::getInstance()->superuser['password'];
        if (md5($superUserLogin . $superUserPassword) == $tokenAuth) {
            return true;
        }

        // Now checking the list of admin token_auth cached in the Tracker config file
        if (!empty($idSite)
            && $idSite > 0
        ) {
            $website = Piwik_Tracker_Cache::getCacheWebsiteAttributes($idSite);
            $adminTokenAuth = $website['admin_token_auth'];
            if (in_array($tokenAuth, $adminTokenAuth)) {
                return true;
            }
        }
        Common::printDebug("WARNING! token_auth = $tokenAuth is not valid, Super User / Admin was NOT authenticated");

        return false;
    }

    public function getDaysSinceFirstVisit()
    {
        $cookieFirstVisitTimestamp = $this->getParam('_idts');
        if (!$this->isTimestampValid($cookieFirstVisitTimestamp)) {
            $cookieFirstVisitTimestamp = $this->getCurrentTimestamp();
        }
        $daysSinceFirstVisit = round(($this->getCurrentTimestamp() - $cookieFirstVisitTimestamp) / 86400, $precision = 0);
        if ($daysSinceFirstVisit < 0) {
            $daysSinceFirstVisit = 0;
        }
        return $daysSinceFirstVisit;
    }

    public function getDaysSinceLastOrder()
    {
        $daysSinceLastOrder = false;
        $lastOrderTimestamp = $this->getParam('_ects');
        if ($this->isTimestampValid($lastOrderTimestamp)) {
            $daysSinceLastOrder = round(($this->getCurrentTimestamp() - $lastOrderTimestamp) / 86400, $precision = 0);
            if ($daysSinceLastOrder < 0) {
                $daysSinceLastOrder = 0;
            }
        }
        return $daysSinceLastOrder;
    }

    public function getDaysSinceLastVisit()
    {
        $daysSinceLastVisit = 0;
        $lastVisitTimestamp = $this->getParam('_viewts');
        if ($this->isTimestampValid($lastVisitTimestamp)) {
            $daysSinceLastVisit = round(($this->getCurrentTimestamp() - $lastVisitTimestamp) / 86400, $precision = 0);
            if ($daysSinceLastVisit < 0) {
                $daysSinceLastVisit = 0;
            }
        }
        return $daysSinceLastVisit;
    }

    public function getVisitCount()
    {
        $visitCount = $this->getParam('_idvc');
        if ($visitCount < 1) {
            $visitCount = 1;
        }
        return $visitCount;
    }

    /**
     * Returns the language the visitor is viewing.
     *
     * @return string browser language code, eg. "en-gb,en;q=0.5"
     */
    public function getBrowserLanguage()
    {
        return Common::getRequestVar('lang', Common::getBrowserLanguage(), 'string', $this->params);
    }

    public function getLocalTime()
    {
        $localTimes = array(
            'h' => (string)Common::getRequestVar('h', $this->getCurrentDate("H"), 'int', $this->params),
            'i' => (string)Common::getRequestVar('m', $this->getCurrentDate("i"), 'int', $this->params),
            's' => (string)Common::getRequestVar('s', $this->getCurrentDate("s"), 'int', $this->params)
        );
        foreach ($localTimes as $k => $time) {
            if (strlen($time) == 1) {
                $localTimes[$k] = '0' . $time;
            }
        }
        $localTime = $localTimes['h'] . ':' . $localTimes['i'] . ':' . $localTimes['s'];
        return $localTime;
    }

    /**
     * Returns the current date in the "Y-m-d" PHP format
     *
     * @param string $format
     * @return string
     */
    protected function getCurrentDate($format = "Y-m-d")
    {
        return date($format, $this->getCurrentTimestamp());
    }

    public function getGoalRevenue($defaultGoalRevenue)
    {
        return Common::getRequestVar('revenue', $defaultGoalRevenue, 'float', $this->params);
    }

    public function getParam($name)
    {
        $supportedParams = array(
            // Name => array( defaultValue, type )
            '_refts'       => array(0, 'int'),
            '_ref'         => array('', 'string'),
            '_rcn'         => array('', 'string'),
            '_rck'         => array('', 'string'),
            '_idts'        => array(0, 'int'),
            '_viewts'      => array(0, 'int'),
            '_ects'        => array(0, 'int'),
            '_idvc'        => array(1, 'int'),
            'url'          => array('', 'string'),
            'urlref'       => array('', 'string'),
            'res'          => array(self::UNKNOWN_RESOLUTION, 'string'),
            'idgoal'       => array(-1, 'int'),

            // other
            'dp'           => array(0, 'int'),
            'rec'          => array(false, 'int'),
            'new_visit'    => array(0, 'int'),

            // Ecommerce
            'ec_id'        => array(false, 'string'),
            'ec_st'        => array(false, 'float'),
            'ec_tx'        => array(false, 'float'),
            'ec_sh'        => array(false, 'float'),
            'ec_dt'        => array(false, 'float'),
            'ec_items'     => array('', 'string'),

            // some visitor attributes can be overwritten
            'cip'          => array(false, 'string'),
            'cdt'          => array(false, 'string'),
            'cid'          => array(false, 'string'),

            // Actions / pages
            'cs'           => array(false, 'string'),
            'download'     => array('', 'string'),
            'link'         => array('', 'string'),
            'action_name'  => array('', 'string'),
            'search'       => array('', 'string'),
            'search_cat'   => array(false, 'string'),
            'search_count' => array(-1, 'int'),
            'gt_ms'        => array(-1, 'int'),
        );

        if (!isset($supportedParams[$name])) {
            throw new Exception("Requested parameter $name is not a known Tracking API Parameter.");
        }
        $paramDefaultValue = $supportedParams[$name][0];
        $paramType = $supportedParams[$name][1];

        $value = Common::getRequestVar($name, $paramDefaultValue, $paramType, $this->params);

        return $value;
    }

    public function getCurrentTimestamp()
    {
        return $this->timestamp;
    }

    protected function isTimestampValid($time)
    {
        return $time <= $this->getCurrentTimestamp()
            && $time > $this->getCurrentTimestamp() - 10 * 365 * 86400;
    }

    public function getIdSite()
    {
        $idSite = Common::getRequestVar('idsite', 0, 'int', $this->params);
        Piwik_PostEvent('Tracker.setRequest.idSite', array(&$idSite, $this->params));
        if ($idSite <= 0) {
            throw new Exception('Invalid idSite');
        }
        return $idSite;
    }

    public function getUserAgent()
    {
        $default = @$_SERVER['HTTP_USER_AGENT'];
        return Common::getRequestVar('ua', is_null($default) ? false : $default, 'string', $this->params);
    }

    public function getCustomVariables($scope)
    {
        if ($scope == 'visit') {
            $parameter = '_cvar';
        } else {
            $parameter = 'cvar';
        }

        $customVar = Common::unsanitizeInputValues(Common::getRequestVar($parameter, '', 'json', $this->params));
        if (!is_array($customVar)) {
            return array();
        }
        $customVariables = array();
        foreach ($customVar as $id => $keyValue) {
            $id = (int)$id;
            if ($id < 1
                || $id > Tracker::MAX_CUSTOM_VARIABLES
                || count($keyValue) != 2
                || (!is_string($keyValue[0]) && !is_numeric($keyValue[0]))
            ) {
                Common::printDebug("Invalid custom variables detected (id=$id)");
                continue;
            }
            if (strlen($keyValue[1]) == 0) {
                $keyValue[1] = "";
            }
            // We keep in the URL when Custom Variable have empty names
            // and values, as it means they can be deleted server side

            $key = self::truncateCustomVariable($keyValue[0]);
            $value = self::truncateCustomVariable($keyValue[1]);
            $customVariables['custom_var_k' . $id] = $key;
            $customVariables['custom_var_v' . $id] = $value;
        }

        return $customVariables;
    }

    static public function truncateCustomVariable($input)
    {
        return substr(trim($input), 0, Tracker::MAX_LENGTH_CUSTOM_VARIABLE);
    }

    protected function shouldUseThirdPartyCookie()
    {
        return (bool)Config::getInstance()->Tracker['use_third_party_id_cookie'];
    }

    /**
     * Update the cookie information.
     */
    public function setThirdPartyCookie($idVisitor)
    {
        if (!$this->shouldUseThirdPartyCookie()) {
            return;
        }
        Common::printDebug("We manage the cookie...");

        $cookie = $this->makeThirdPartyCookie();
        // idcookie has been generated in handleNewVisit or we simply propagate the old value
        $cookie->set(0, bin2hex($idVisitor));
        $cookie->save();
    }

    protected function makeThirdPartyCookie()
    {
        $cookie = new Cookie(
            $this->getCookieName(),
            $this->getCookieExpire(),
            $this->getCookiePath());
        Common::printDebug($cookie);
        return $cookie;
    }

    protected function getCookieName()
    {
        return Config::getInstance()->Tracker['cookie_name'];
    }

    protected function getCookieExpire()
    {
        return $this->getCurrentTimestamp() + Config::getInstance()->Tracker['cookie_expire'];
    }

    protected function getCookiePath()
    {
        return Config::getInstance()->Tracker['cookie_path'];
    }

    /**
     * Is the request for a known VisitorId, based on 1st party, 3rd party (optional) cookies or Tracking API forced Visitor ID
     * @throws Exception
     */
    public function getVisitorId()
    {
        $found = false;

        // Was a Visitor ID "forced" (@see Tracking API setVisitorId()) for this request?
        $idVisitor = $this->getForcedVisitorId();
        if (!empty($idVisitor)) {
            if (strlen($idVisitor) != Tracker::LENGTH_HEX_ID_STRING) {
                throw new Exception("Visitor ID (cid) $idVisitor must be " . Tracker::LENGTH_HEX_ID_STRING . " characters long");
            }
            Common::printDebug("Request will be recorded for this idvisitor = " . $idVisitor);
            $found = true;
        }

        // - If set to use 3rd party cookies for Visit ID, read the cookie
        if (!$found) {
            // - By default, reads the first party cookie ID
            $useThirdPartyCookie = $this->shouldUseThirdPartyCookie();
            if ($useThirdPartyCookie) {
                $cookie = $this->makeThirdPartyCookie();
                $idVisitor = $cookie->get(0);
                if ($idVisitor !== false
                    && strlen($idVisitor) == Tracker::LENGTH_HEX_ID_STRING
                ) {
                    $found = true;
                }
            }
        }
        // If a third party cookie was not found, we default to the first party cookie
        if (!$found) {
            $idVisitor = Common::getRequestVar('_id', '', 'string', $this->params);
            $found = strlen($idVisitor) >= Tracker::LENGTH_HEX_ID_STRING;
        }

        if ($found) {
            $truncated = substr($idVisitor, 0, Tracker::LENGTH_HEX_ID_STRING);
            $binVisitorId = @Common::hex2bin($truncated);
            if (!empty($binVisitorId)) {
                return $binVisitorId;
            }
        }
        return false;
    }

    public function getIp()
    {
        if (!empty($this->enforcedIp)) {
            $ipString = $this->enforcedIp;
        } else {
            $ipString = IP::getIpFromHeader();
        }
        $ip = IP::P2N($ipString);
        return $ip;
    }

    public function setForceIp($ip)
    {
        $this->enforcedIp = $ip;
    }

    public function setForceDateTime($dateTime)
    {
        if (!is_numeric($dateTime)) {
            $dateTime = strtotime($dateTime);
        }
        $this->timestamp = $dateTime;
    }

    public function setForcedVisitorId($visitorId)
    {
        $this->forcedVisitorId = $visitorId;
    }

    public function getForcedVisitorId()
    {
        return $this->forcedVisitorId;
    }

    public function enrichLocation($location)
    {
        if (!$this->isAuthenticated()) {
            return $location;
        }

        // check for location override query parameters (ie, lat, long, country, region, city)
        $locationOverrideParams = array(
            'country' => array('string', Piwik_UserCountry_LocationProvider::COUNTRY_CODE_KEY),
            'region'  => array('string', Piwik_UserCountry_LocationProvider::REGION_CODE_KEY),
            'city'    => array('string', Piwik_UserCountry_LocationProvider::CITY_NAME_KEY),
            'lat'     => array('float', Piwik_UserCountry_LocationProvider::LATITUDE_KEY),
            'long'    => array('float', Piwik_UserCountry_LocationProvider::LONGITUDE_KEY),
        );
        foreach ($locationOverrideParams as $queryParamName => $info) {
            list($type, $locationResultKey) = $info;

            $value = Common::getRequestVar($queryParamName, false, $type, $this->params);
            if (!empty($value)) {
                $location[$locationResultKey] = $value;
            }
        }
        return $location;
    }

    public function getPlugins()
    {
        $pluginsInOrder = array('fla', 'java', 'dir', 'qt', 'realp', 'pdf', 'wma', 'gears', 'ag', 'cookie');
        $plugins = array();
        foreach ($pluginsInOrder as $param) {
            $plugins[] = Common::getRequestVar($param, 0, 'int', $this->params);
        }
        return $plugins;
    }

    public function getParamsCount()
    {
        return count($this->params);
    }
}
