<?php
class FeverAPI extends Handler {

    const API_LEVEL  = 3;

    const STATUS_OK  = 1;
    const STATUS_ERR = 0;
    
    // enable if you need some debug output in your tinytinyrss root
    const DEBUG              = FALSE;
    // your user id you need to debug - look it up in your mysql database and set it to a value bigger than 0
    const DEBUG_USER         = 0; 
    
    const PLUGIN_NAME        = "Fever";

    private $id_hack = FALSE;
    
    // add link in bottom for attached files
    private $add_attached_files = TRUE;
    
    // output as xml or json
    private $xml;
    
    // find the user in the db with a particular api key
    private function setUser()
    {        
        $apikey = isset($_REQUEST["api_key"]) ? clean($_REQUEST["api_key"]) : "";
        
        // Login for Mr. Reader
        if (strlen($apikey) <= 0 &&
            isset($_REQUEST["action"]) &&
            clean($_REQUEST["action"]) === "login" &&
            isset($_REQUEST["email"])&&
            isset($_REQUEST["password"])) 
        {
            $email = $_REQUEST["email"];
            $password = $_REQUEST["password"];
            
            $apikey = strtoupper(md5($email . ":" . $password));
            
            setcookie("fever_auth", $apikey, time() + max(SESSION_COOKIE_LIFETIME, 60 * 60 * 24));
        }
        
        // override for Mr.Reader when doing some stuff
        if (strlen($apikey) <= 0 && isset($_COOKIE["fever_auth"])) 
        { 
            $apikey = $_COOKIE['fever_auth'];
        }
                
        if (strlen($apikey) > 0)
        {
            $sth = $this->pdo->prepare("SELECT owner_uid, content FROM ttrss_plugin_storage
                                        WHERE name = ?");
            $sth->execute([self::PLUGIN_NAME]);
                                                     
            while ($line = $sth->fetch())
            {
                $obj = unserialize($line["content"]);
                if ($obj && 
                    isset($obj["password"]) && 
                    strtolower($obj["password"]) === strtolower($apikey))
                {
                    $_SESSION["uid"] = $line["owner_uid"];
                    break;
                }
            }

            if (self::DEBUG_USER > 0) 
            {
                $_SESSION["uid"] = self::DEBUG_USER; // always authenticate and set debug user
            }
        }
    }

    // set whether xml or json
    private function setXml()
    {
        $this->xml = false;
        if (isset($_REQUEST["api"]))
        {
            if (strtolower(clean($_REQUEST["api"])) === "xml")
            {
                $this->xml = true;
            }
        }
    }
    
    private function setIdHack()
    {
        $this->id_hack = false;
        
        $user_agent = false;
        if (isset($_SERVER["HTTP_USER_AGENT"])) 
        {
            $user_agent = $_SERVER["HTTP_USER_AGENT"];
        }
            
        // Check for all client in Android except ReadKit in Mac, Mr. Reader and Dalvik
        if ($user_agent &&
            (strpos($user_agent, "Dalvik") !== FALSE ||
             strpos($user_agent, "ReadKit") !== FALSE ||
             strpos($user_agent, "Mr. Reader") !== FALSE)) 
        {
            $this->id_hack = true;
        }
    }
    
    // validate the api_key, user preferences
    function before($method) {
        /* classes/api.php before */
        
        if (parent::before($method)) {
            if (self::DEBUG) {
                // add request to debug log
                error_log(print_r($_REQUEST, true));
            }

            // set the user from the db
            $this->setUser();

            // are we xml or json?
            $this->setXml();
            
            // do we need to apply the ID hack
            $this->setIdHack();

            if ($this->xml)
                header("Content-Type: text/xml");
            else
                header("Content-Type: text/json");

            // check we have a valid user
            if (!$_SESSION["uid"]) {
                $this->wrap(self::STATUS_ERR, array("error" => 'NOT_LOGGED_IN'));
                return false;
            }

            // check if user has api access enabled
            if ($_SESSION["uid"] && !get_pref('ENABLE_API_ACCESS')) {
                $this->wrap(self::STATUS_ERR, array("error" => 'API_DISABLED'));
                return false;
            }

            return true;
        }
        return false;
    }

    // always include api_version, status as 'auth'
    // output json/xml
    function wrap($status, $reply)
    {
        /* classes/api.php wrap */
        $arr = array("api_version" => self::API_LEVEL,
                     "auth" => $status);
                     
        if (!empty($reply) && is_array($reply))
        {
            $arr = array_merge($arr, $reply);
        }

        if ($status == self::STATUS_OK)
        {
            $arr["last_refreshed_on_time"] = (string)$this->lastRefreshedOnTime();
        }

        $resp = "";
        if ($this->xml)
        {
            $resp = $this->array_to_xml($arr);
        }
        else
        {
            $resp = json_encode($arr);
        }
        
        print $resp;
        
        if (self::DEBUG)
        {
            // debug output
            error_log(print_r($resp, true));
        }
    }

    // fever supports xml wrapped in <response> tags
    // TODO: holy crap replace this junk
    private function array_to_xml($array, $container = 'response', $is_root = true)
    {
        if (!is_array($array)) return array_to_xml(array($array));

        $xml = '';

        if ($is_root)
        {
            $xml .= '<?xml version="1.0" encoding="utf-8"?>';
            $xml .= "<{$container}>";
        }

        foreach($array as $key => $value)
        {
            // make sure key is a string
            $elem = $key;

            if (!is_string($key) && !empty($container))
            {
                $elem = $container;
            }

            $xml .= "<{$elem}>";

            if (is_array($value))
            {
                if (array_keys($value) !== array_keys(array_keys($value)))
                {
                    $xml .= array_to_xml($value, '', false);
                }
                else
                {
                    $xml .= array_to_xml($value, r('/s$/', '', $elem), false);
                }
            }
            else
            {
                $xml .= (htmlspecialchars($value, ENT_COMPAT, 'ISO-8859-1') != $value) ? "<![CDATA[{$value}]]>" : $value;
            }

            $xml .= "</{$elem}>";
        }

        if ($is_root)
        {
            $xml .= "</{$container}>";
        }

        return preg_replace('/[\x00-\x1F\x7F]/', '', $xml);
    }

    // every authenticated method includes last_refreshed_on_time
    private function lastRefreshedOnTime()
    {
        $sth = $this->pdo->prepare("SELECT " . SUBSTRING_FOR_DATE . "(last_updated,1,19) AS last_updated 
                                    FROM ttrss_feeds
                                    WHERE owner_uid = ?
                                    ORDER BY last_updated DESC");
        $sth->execute([clean($_SESSION["uid"])]);

        if ($row = $sth->fetch())
        {
            $last_refreshed_on_time = (int) strtotime($row["last_updated"]);
        }
        else
        {
            $last_refreshed_on_time = 0;
        }

        return $last_refreshed_on_time;
    }

    private function flattenGroups(&$groupsToGroups, &$groups, &$groupsToTitle, $index)
    {
        foreach ($groupsToGroups[$index] as $item)
        {
            $id = substr($item, strpos($item, "-") + 1);
            array_push($groups, array("id" => intval($id), "title" => $groupsToTitle[$id]));
            if (isset($groupsToGroups[$id]))
                $this->flattenGroups($groupsToGroups, $groups, $groupsToTitle, $id);
        }
    }

    function getGroups()
    {
        // TODO: ordering of child categories etc
        $groups = array();

        $sth = $this->pdo->prepare("SELECT id, title, parent_cat
                                    FROM ttrss_feed_categories
                                    WHERE owner_uid = ?
                                    ORDER BY order_id ASC");
        $sth->execute([clean($_SESSION["uid"])]);

        $groupsToGroups = array();
        $groupsToTitle = array();

        while ($line = $sth->fetch())
        {
            if ($line["parent_cat"] === NULL)
            {
                if (!isset($groupsToGroups[-1]))
                {
                    $groupsToGroups[-1] = array();
                }

                array_push($groupsToGroups[-1], $line["order_id"] . "-" . $line["id"]);
            }
            else
            {
                if (!isset($groupsToGroups[$line["parent_cat"]]))
                {
                    $groupsToGroups[$line["parent_cat"]] = array();
                }

                array_push($groupsToGroups[$line["parent_cat"]], $line["order_id"] . "-" . $line["id"]);
            }

            $groupsToTitle[$line["id"]] = $line["title"];
        }

        foreach ($groupsToGroups as $key => $value)
        {
            sort($value);
        }

        if (isset($groupsToGroups[-1]))
            $this->flattenGroups($groupsToGroups, $groups, $groupsToTitle, -1);

        return $groups;
    }

    function getFeeds()
    {
        $feeds = array();

        $sth = $this->pdo->prepare("SELECT id, title, feed_url, site_url, " . SUBSTRING_FOR_DATE . "(last_updated,1,19) AS last_updated
                                    FROM ttrss_feeds
                                    WHERE owner_uid = ?
                                    ORDER BY order_id ASC");
        $sth->execute([clean($_SESSION["uid"])]);

        while ($line = $sth->fetch())
        {
            array_push($feeds, array("id" => intval($line["id"]),
                                     "favicon_id" => intval($line["id"]),
                                     "title" => $line["title"],
                                     "url" => $line["feed_url"],
                                     "site_url" => $line["site_url"],
                                     "is_spark" => 0, // unsupported
                                     "last_updated_on_time" => (int) strtotime($line["last_updated"])
                    ));
        }
        return $feeds;
    }

    function getFavicons()
    {
        $favicons = array();

        $sth = $this->pdo->prepare("SELECT id
                                    FROM ttrss_feeds
                                    WHERE owner_uid = ?
                                    ORDER BY order_id ASC");
        $sth->execute([clean($_SESSION["uid"])]);

        // data = "image/gif;base64,<base64 encoded image>
        while ($line = $sth->fetch())
        {
            $filename = "feed-icons/" . $line["id"] . ".ico";
            if (file_exists($filename))
            {
                array_push($favicons, array("id" => intval($line["id"]),
                                            "data" => image_type_to_mime_type(exif_imagetype($filename)) . ";base64," . base64_encode(file_get_contents($filename))
                ));
            }
        }

        return $favicons;
    }

    function getLinks()
    {
        // TODO: is there a 'hot links' alternative in ttrss?
        // use ttrss_user_entries / score > 0 / unread
        $links = array();

        $item_limit = 50;
        $where = "owner_uid = ? AND ref_id = id AND score > 0 AND unread = true";
        $where_items = array();
        array_push($where_items, clean($_SESSION["uid"]));

        if (isset($_REQUEST["range"]))
        {
            // use the range argument to request a limited "updated" items
            if (is_numeric($_REQUEST["range"]))
                {
                $range = ($_REQUEST["range"] > 0) ? intval(clean($_REQUEST["range"])) : 0;
                if ($range)
                {

                    $offset = 0;
                    if (isset($_REQUEST["offset"]))
                    {
                        // use the range argument to request a limited "updated" items
                        if (is_numeric($_REQUEST["offset"]))
                        {
                            $offset = ($_REQUEST["offset"] > 0) ? intval(clean($_REQUEST["offset"])) : 0;
                        }
                    }

                    if ($range) {
                        if ($offset == 0) {
                            //range > 1 AND offset = 0
                            $where .= " AND updated < NOW()";
                            $where .= " AND updated > NOW()-INTERVAL ? DAY";
                            array_push($where_items, $range);
                        } else {
                            //range > 1 AND offset > 0
                            $where .= " AND updated < NOW()-INTERVAL ? DAY";
                            $where .= " AND updated > NOW()-INTERVAL ? DAY";
                            array_push($where_items, $offset, $offset+$range);
                        }
                    }
                }
            }
        }

        $where .= " ORDER BY score DESC, updated DESC" ;

        if (is_numeric($_REQUEST["page"]))
        {
            // use the page argument to request the next $item_limit items
            // page = 1 --> 1st Page will be convertet to 0
            $page = isset($_REQUEST["page"]) ? intval(clean($_REQUEST["page"]))-1 : 0;
            $page = ($page<0) ? 0 : $page;

            $where .= " LIMIT " . $item_limit . " OFFSET " . intval($page * $item_limit);
            // array_push($where_items, $item_limit);
            // array_push($where_items, ($page * $item_limit));
        } else {
            $where .= " LIMIT ?";
            array_push($where_items, $item_limit);
        }

        /* classes/api.php getLinks */

        // id, feed_id, title, author, html, url, is_saved, is_read, created_on_time
        $sth = $this->pdo->prepare("SELECT ref_id, feed_id, title, link, score, id, marked, unread, updated
                                   FROM ttrss_entries, ttrss_user_entries
                                   WHERE " . $where);
        $sth->execute($where_items);

        while ($line = $sth->fetch())
        {
            array_push($links, array("id" => intval($line["id"]),
                                     "feed_id" => intval($line["feed_id"]),
                                     "item_id" => intval($line["ref_id"]),
                                     "temperature" => intval($line["score"]),
                                     "is_item" => 1,
                                     "is_local" => 1,
                                     "is_saved" => (API::param_to_bool($line["marked"]) ? 1 : 0),
                                     "title" => $line["title"],
                                     "url" => $line["link"],
                                     "item_ids" => ""
            ));
        }

        return $links;
    }

    function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        // Uncomment one of the following alternatives
        $bytes /= pow(1024, $pow);
        // $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    function getItems()
    {
        // items from specific groups, feeds
        $items = array();

        $item_limit = 50;
        $where = " owner_uid = ? AND ref_id = id ";
        $where_items = array();
        array_push($where_items, clean($_SESSION["uid"]));

        if (isset($_REQUEST["feed_ids"]) || isset($_REQUEST["group_ids"])) // added 0.3
        {
            $feed_ids = array();
            if (isset($_REQUEST["feed_ids"]))
            {
                $feed_ids = explode(",", clean($_REQUEST["feed_ids"]));
            }
            if (isset($_REQUEST["group_ids"]))
            {
                $group_ids = array_map("intval", array_filter(explode(",", clean($_REQUEST["group_ids"])), "is_numeric"));
                $group_ids_qmarks = arr_qmarks($group_ids);
                
                $sth = $this->pdo->prepare("SELECT id
                                           FROM ttrss_feeds
                                           WHERE owner_uid = ? AND cat_id IN ($group_ids_qmarks)");
                                           
                $sth->execute(array_merge([clean($_SESSION["uid"])], $group_ids));

                $group_feed_ids = array();
                while ($line = $sth->fetch())
                {
                    array_push($group_feed_ids, $line["id"]);
                }

                $feed_ids = array_unique(array_merge($feed_ids, $group_feed_ids));
            }

            $feed_ids = array_map("intval", array_filter($feed_ids, "is_numeric"));
            $feed_ids_qmarks = arr_qmarks($feed_ids);
            
            $where .= " AND feed_id IN ($feed_ids_qmarks) ";
            $where_items = array_merge($where_items, $feed_ids);
        }

        if (isset($_REQUEST["max_id"])) // descending from most recently added
        {
            // use the max_id argument to request the previous $item_limit items
            if (is_numeric($_REQUEST["max_id"]))
            {
                $max_id = ($_REQUEST["max_id"] > 0) ? intval(clean($_REQUEST["max_id"])) : 0;
                if ($max_id)
                {
                    $where .= " AND id < ? ";
                    array_push($where_items, $max_id);
                }

                $where .= " ORDER BY id DESC ";
            }
        }
        else if (isset($_REQUEST["with_ids"])) // selective
        {
            $item_ids = array_map("intval", array_filter(explode(",", clean($_REQUEST["with_ids"])), "is_numeric"));
            $item_ids_qmarks = arr_qmarks($item_ids);
            
            $where .= " AND id IN ($item_ids_qmarks) ";
            $where_items = array_merge($where_items, $item_ids);
        }
        else // ascending from first added
        {
            if (is_numeric($_REQUEST["since_id"]))
            {
                // use the since_id argument to request the next $item_limit items
                $since_id = isset($_GET["since_id"]) ? intval(clean($_GET["since_id"])) : 0;

                if ($since_id)
                {
                    $where .= " AND id > ? ";
                    
                    if ($this->id_hack)
                    {
                        $val = $since_id * 1000; // NASTY hack for Mr. Reader 2.0 on iOS and TinyTiny RSS Fever
                    }
                    else 
                    {
                        $val = $since_id;
                    }
                    
                    array_push($where_items, $val);
                }

                $where .= " ORDER BY id ASC ";
            }
        }

        $where .= " LIMIT " . $item_limit;

        /* classes/api.php getArticle */
        
        // id, feed_id, title, author, html, url, is_saved, is_read, created_on_time
        $sth = $this->pdo->prepare("SELECT ref_id, feed_id, title, link, content, id, marked, unread, author, 
                                   " . SUBSTRING_FOR_DATE . "(updated,1,16) as updated,
                                   (SELECT site_url FROM ttrss_feeds WHERE id = feed_id) AS site_url,
                                   (SELECT hide_images FROM ttrss_feeds WHERE id = feed_id) AS hide_images
                                   FROM ttrss_entries, ttrss_user_entries
                                   WHERE " . $where);
        $sth->execute($where_items);

        while ($line = $sth->fetch())
        {            
            $line_content = sanitize(
                                $line["content"],
                                API::param_to_bool($line['hide_images']),
                                false, $line["site_url"], false, $line["id"]);
            
            if ($this->add_attached_files){
                $enclosures = Article::get_article_enclosures($line["id"]);
                if (count($enclosures) > 0) {
                    $line_content .= '<ul type="lower-greek">';
                    foreach ($enclosures as $enclosure) {
                        if (!empty($enclosure["content_url"])) {
                            $enc_type = "";
                            if (!empty($enclosure["content_type"])) {
                                $enc_type = ", " . $enclosure["content_type"];
                            }
                            $enc_size = "";
                            if (!empty($enclosure["duration"])) {
                                $enc_size = " , " . $this->formatBytes($enclosure["duration"]);
                            }
                            $line_content .= '<li><a href="' . $enclosure["content_url"] . '" target="_blank">' . basename($enclosure["content_url"]) . $enc_type . $enc_size . '</a></li>';
                        }
                    }
                    $line_content .= '</ul>';
                }
            }
            
            array_push($items, array("id" => intval($line["id"]),
                                     "feed_id" => intval($line["feed_id"]),
                                     "title" => $line["title"],
                                     "author" => $line["author"],
                                     "html" => $line_content,
                                     "url" => $line["link"],
                                     "is_saved" => (API::param_to_bool($line["marked"]) ? 1 : 0),
                                     "is_read" => ( (!API::param_to_bool($line["unread"])) ? 1 : 0),
                                     "created_on_time" => (int) strtotime($line["updated"])
                    ));
        }

        return $items;
    }

    function getTotalItems()
    {
        // number of total items
        $total_items = 0;

        $sth = $this->pdo->prepare("SELECT COUNT(ref_id) as total_items
                                    FROM ttrss_user_entries
                                    WHERE owner_uid = ?");
        $sth->execute([clean($_SESSION["uid"])]);

        if ($line = $sth->fetch())
        {
            $total_items = $line["total_items"];
        }

        return $total_items;
    }

    function getFeedsGroup()
    {
        $feeds_groups = array();

        $sth = $this->pdo->prepare("SELECT id, cat_id
                                    FROM ttrss_feeds
                                    WHERE owner_uid = ?
                                    AND cat_id IS NOT NULL
                                    ORDER BY id ASC");
        $sth->execute([clean($_SESSION["uid"])]);

        $groupsToFeeds = array();

        while ($line = $sth->fetch())
        {
            if (!array_key_exists($line["cat_id"], $groupsToFeeds))
                $groupsToFeeds[$line["cat_id"]] = array();

            array_push($groupsToFeeds[$line["cat_id"]], $line["id"]);
        }

        foreach ($groupsToFeeds as $group => $feeds)
        {
            $feedsStr = "";
            foreach ($feeds as $feed)
                $feedsStr .= $feed . ",";
            $feedsStr = trim($feedsStr, ",");

            array_push($feeds_groups, array("group_id" => $group,
                                            "feed_ids" => $feedsStr));
        }
        return $feeds_groups;
    }

    function getUnreadItemIds()
    {
        $unreadItemIdsCSV = "";
        $sth = $this->pdo->prepare("SELECT ref_id
                                    FROM ttrss_user_entries
                                    WHERE owner_uid = ? AND unread = true"); // ORDER BY red_id DESC
        $sth->execute([clean($_SESSION["uid"])]);

        while ($line = $sth->fetch())
        {
            $unreadItemIdsCSV .= $line["ref_id"] . ",";
        }
        $unreadItemIdsCSV = trim($unreadItemIdsCSV, ",");

        return $unreadItemIdsCSV;
    }

    function getSavedItemIds()
    {
        $savedItemIdsCSV = "";
        $sth = $this->pdo->prepare("SELECT ref_id
                                    FROM ttrss_user_entries
                                    WHERE owner_uid = ? AND marked = true");
        $sth->execute([clean($_SESSION["uid"])]);

        while ($line = $sth->fetch())
        {
            $savedItemIdsCSV .= $line["ref_id"] . ",";
        }
        $savedItemIdsCSV = trim($savedItemIdsCSV, ",");

        return $savedItemIdsCSV;
    }

    function getEqualItems($id)
    {
        //get all ids which have identical links (Reference is found by id)
        $sth = $this->pdo->prepare("SELECT id 
                                    FROM ttrss_entries,ttrss_user_entries
                                    WHERE id=ref_id AND owner_uid = ?
                                    AND link=(SELECT link FROM ttrss_entries WHERE id = ?)");
        $sth->execute(array_merge([clean($_SESSION["uid"]), $id]));

        $ids = "";
        while ($line = $sth->fetch())
        {
            $ids .= $line["id"] . ",";
        }
        $ids = trim($ids, ",");

        if (self::DEBUG) {
            // add request to debug log
            error_log(print_r($ids, true));
        }

        return $ids;
    }

    function setItem($id, $field_raw, $mode)
    {
        /* classes/api.php updateArticle */
        
        $article_ids = array_map("intval", array_filter(explode(",", clean($id)), "is_numeric"));
        $mode = (int) clean($mode);
        $field_raw = (int) clean($field_raw);        
        
        $field = "";
        $set_to = "";

        switch ($field_raw) {
            case 0:
                $field = "marked";
                $additional_fields = ",last_marked = NOW()";
                break;
            case 1:
                $field = "unread";
                $additional_fields = ",last_read = NOW()";
                break;
        };

        switch ($mode) {
            case 1:
                $set_to = "true";
                break;
            case 0:
                $set_to = "false";
                break;
        }

        if ($field && $set_to && count($article_ids) > 0) {
            $article_qmarks = arr_qmarks($article_ids);
            
            $sth = $this->pdo->prepare("UPDATE ttrss_user_entries SET 
                                        $field = $set_to $additional_fields
                                        WHERE ref_id IN ($article_qmarks) AND owner_uid = ?");
            $sth->execute(array_merge($article_ids, [clean($_SESSION["uid"])]));
            
            $num_updated = $sth->rowCount();

            if ($num_updated > 0 && $field == "unread") {
                $sth = $this->pdo->prepare("SELECT DISTINCT feed_id FROM ttrss_user_entries
                                            WHERE ref_id IN ($article_qmarks)");
                $sth->execute($article_ids);

                while ($line = $sth->fetch()) {
                    CCache::update($line["feed_id"], clean($_SESSION["uid"]));
                }
            }
        }
    }

    function setItemAsRead($id)
    {
        //action is true for all Equal Items
        $ids = $this->getEqualItems($id);
        $this->setItem($ids, 1, 0);
    }

    function setItemAsUnread($id)
    {
        $ids = $this->getEqualItems($id);
        $this->setItem($ids, 1, 1);
    }

    function setItemAsSaved($id)
    {
        $this->setItem($id, 0, 1);
    }

    function setItemAsUnsaved($id)
    {
        $this->setItem($id, 0, 0);
    }

    function setFeed($id, $cat, $before=0)
    {
        /* classes/feeds.php catchup_feed */
        
        // if before is zero, set it to now so feeds all items are read from before this point in time
        if ($before == 0)
            $before = time();

        if (is_numeric($id))
        {
            // this is a category
            if ($cat)
            {
                // if not special feed
                if ($id > 0)
                {
                    $sth = $this->pdo->prepare("UPDATE ttrss_user_entries
                                                SET unread = false, last_read = NOW() WHERE ref_id IN
                                                (SELECT id FROM
                                                    (SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
                                                     AND owner_uid = ? AND unread = true AND feed_id IN
                                                         (SELECT id FROM ttrss_feeds WHERE cat_id IN (?)) AND updated < ? ) as tmp)");
                    $sth->execute([clean($_SESSION["uid"]), intval($id), date("Y-m-d H:i:s", $before)]);
                }
                // this is "all" to fever, but internally "all" is -4
                else if ($id == 0)
                {
                    $id = -4;
                    $sth = $this->pdo->prepare("UPDATE ttrss_user_entries
                                                SET unread = false, last_read = NOW() WHERE ref_id IN
                                                (SELECT id FROM
                                                    (SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
                                                     AND owner_uid = ? AND unread = true AND updated < ? ) as tmp)");
                    $sth->execute([clean($_SESSION["uid"]), date("Y-m-d H:i:s", $before)]);
                }
            }
            // not a category
            else if ($id > 0)
            {
                $sth = $this->pdo->prepare("UPDATE ttrss_user_entries
                                            SET unread = false, last_read = NOW() WHERE ref_id IN
                                            (SELECT id FROM
                                                (SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
                                                 AND owner_uid = ? AND unread = true AND feed_id = ? AND updated < ? ) as tmp)");
                $sth->execute([clean($_SESSION["uid"]), intval($id), date("Y-m-d H:i:s", $before)]);

            }
            CCache::update($id, clean($_SESSION["uid"]), $cat);
        }
    }

    function setFeedAsRead($id, $before)
    {
        $this->setFeed($id, false, $before);
    }

    function setGroupAsRead($id, $before)
    {
        $this->setFeed($id, true, $before);
    }

    // this does all the processing, since the fever api does not have a specific variable that specifies the operation
    function index()
    {
        $response_arr = array();

        if (isset($_REQUEST["groups"]))
        {
            $response_arr["groups"] = $this->getGroups();
            $response_arr["feeds_groups"] = $this->getFeedsGroup();
        }
        if (isset($_REQUEST["feeds"]))
        {
            $response_arr["feeds"] = $this->getFeeds();
            $response_arr["feeds_groups"] = $this->getFeedsGroup();
        }
        // TODO: favicon support
        if (isset($_REQUEST["favicons"]))
        {
            $response_arr["favicons"] = $this->getFavicons();
        }
        if (isset($_REQUEST["items"]))
        {
            $response_arr["total_items"] = $this->getTotalItems();
            $response_arr["items"] = $this->getItems();
        }
        if (isset($_REQUEST["links"]))
        {
            $response_arr["links"] = $this->getLinks();
        }
        if (isset($_REQUEST["unread_item_ids"]))
        {
            $response_arr["unread_item_ids"] = $this->getUnreadItemIds();
        }
        if (isset($_REQUEST["saved_item_ids"]))
        {
            $response_arr["saved_item_ids"] = $this->getSavedItemIds();
        }

        if (isset($_REQUEST["mark"], $_REQUEST["as"], $_REQUEST["id"]))
        {
            foreach (explode(",", clean($_REQUEST["id"])) as $id) {
                $this->markId($id);
            }
        }
        
        /* classes/api.php index */
        if ($_SESSION["uid"])
            $this->wrap(self::STATUS_OK, $response_arr);
        else if (!$_SESSION["uid"])
            $this->wrap(self::STATUS_ERR, array("error" => 'UNKNOWN_METHOD'));

    }
    
    function markId($id)
    {
        if (is_numeric($id))
        {
            $before = (isset($_REQUEST["before"])) ? clean($_REQUEST["before"]) : null;
            if ($before !== null && $before > pow(10,10)) {
                $before = round($before / 1000);
            }
            
            $method_name = "set" . ucfirst(clean($_REQUEST["mark"])) . "As" . ucfirst(clean($_REQUEST["as"]));

            if (method_exists($this, $method_name))
            {
                $this->{$method_name}(intval($id), $before);
                switch(clean($_REQUEST["as"]))
                {
                    case "read":
                    case "unread":
                        $response_arr["unread_item_ids"] = $this->getUnreadItemIds();
                    break;

                    case 'saved':
                    case 'unsaved':
                        $response_arr["saved_item_ids"] = $this->getSavedItemIds();
                    break;
                }
            }
        }
    }
}

?>
