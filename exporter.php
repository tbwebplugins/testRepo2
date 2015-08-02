<?php
/**
 * Exporter
 * @version 1.3
 *
 *          1.1 Bug fix: Show url
 *          1.2 Changes https:// naar http:// in db
 *          1.3 Added support when class zipArchive is not found
 */
if(isset($_GET['g']) AND !isset($_POST['export'])){
    $name = null;
    $g = $_GET['g'];
    if($g == 'db'){
        $name = 'db.sql';
        $ext = 'sql';
    }elseif($g == 'config'){
        $name = 'wp-config.php';
        $ext = 'php';
    }
    if($name !== null){
        $path = __DIR__.'/tb_export/'.$name;
        $str = file_get_contents($path);
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Type: '.$ext.'/plain'); # Don't use application/force-download - it's not a real MIME type, and the Content-Disposition header is sufficient
        header('Content-Length: ' . strlen($str));
        header('Connection: close');
        echo $str;
        exit;
    }
}
include(__DIR__.'/wp-config.php');
ini_set('memory_limit','5G');
class exporter{
    public $sql;
    public $dbUser;
    public $dbPass;
    public $dbHost;
    public $dbName;

    public $currentHost;
    public $newHost;
    public $gitIgnore;
    public $oldPrefix;
    public $newPrefix = 'wp_';

    function getSql(){

    }



    function replaceSql(){
        //Replace normal
        $this->sql  = str_replace('www.'.$this->currentHost,$this->newHost,$this->sql);
        $this->sql  = str_replace(json_encode('www'.$this->currentHost),json_encode($this->newHost),$this->sql);

        $this->sql  = str_replace($this->currentHost,$this->newHost,$this->sql);

        $this->sql  = str_replace(json_encode($this->currentHost),json_encode($this->newHost),$this->sql);

        $this->sql  = self::replace('https://','http://',$this->sql);

    }

    /**
     * Does search and replace for normal text and json encoded text
     *
     * @param string $search
     * @param string $replace
     * @param string $target
     *
     * @return string
     */
    static function replace($search,$replace,$target){
        $target = str_replace($search,$replace,$target);
        $target = str_replace(json_encode($search),json_encode($replace),$target);
        return $target;
    }

    function addUser(){

    }

    function export(){

        $this->sql = $this->backup_tables($this->dbHost,$this->dbUser,$this->dbPass,$this->dbName);
        $this->replaceSql();
        $this->config   = $this->createConfig();
        //$this->gitIgnore    = $this->createGitIgnore;

        if(class_exists('zipArchive')){
            $zip = new ZipArchive();
            $zip_name = "export.zip";

            if($zip->open($zip_name, ZIPARCHIVE::CREATE)!==TRUE){
                print "* Sorry ZIP creation failed at this time";
            }

            $zip->addFromString('wp-config.php',$this->config);
            $zip->addFromString('db.sql',$this->sql);
            //$zip->addFromString('.gitignore',$this->gitIgnore);


            $zip->close();

            if(file_exists($zip_name)){
                // force to download the zip
                header("Pragma: public");
                header("Expires: 0");
                header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                header("Cache-Control: private",false);
                header('Content-type: application/zip');
                header('Content-Disposition: attachment; filename="'.$zip_name.'"');
                readfile($zip_name);
                // remove zip file from temp path
                unlink($zip_name);
            }



        }else{
            if(!is_dir(__DIR__.'/tb_export')){
                mkdir(__DIR__.'/tb_export');
            }
            $config = fopen(__DIR__.'/tb_export/wp-config.php','w');
            fwrite($config,$this->config);
            fclose($config);

            $db = fopen(__DIR__.'/tb_export/db.sql','w');
            fwrite($db,$this->sql);
            fclose($db);

            print 'The class zipArchive could not be found. Instead the files have been placed in /tb_export: <br>';
            print '<a href="exporter.php?g=db"> Get Db.sql </a><br>
            <a href="exporter.php?g=config"> Get wp-config.php </a><br>';

            $baseUrl = str_replace('exporter.php','',$this::selfUrl());


           // print $baseUrl.'/db.sql <br>';

        }
    }

    /* backup the db OR just a table */
    function backup_tables($host,$user,$pass,$name,$tables = '*')
    {
        set_time_limit(0);
        $link = mysql_connect($host,$user,$pass);
        mysql_select_db($name,$link);

        //get all of the tables
        if($tables == '*')
        {
            $tables = array();
            $result = mysql_query('SHOW TABLES');
            while($row = mysql_fetch_row($result))
            {
                $tables[] = $row[0];
            }
        }
        else
        {
            $tables = is_array($tables) ? $tables : explode(',',$tables);
        }
        $return = '';
        //cycle through
        foreach($tables as $table)
        {
            $result = mysql_query('SELECT * FROM '.$table);
            $num_fields = mysql_num_fields($result);

            //    $return.= 'DROP TABLE '.$table.';';
            $row2 = mysql_fetch_row(mysql_query('SHOW CREATE TABLE '.$table));
            $return.= "\n\n".$row2[1].";\n\n";

            for ($i = 0; $i < $num_fields; $i++)
            {
                while($row = mysql_fetch_row($result))
                {
                    $return.= 'INSERT INTO '.$table.' VALUES(';
                    for($j=0; $j<$num_fields; $j++)
                    {
                        $row[$j] = addslashes($row[$j]);
                        $row[$j] = preg_replace("#\n#","\\n",$row[$j]);
                        if (isset($row[$j])) { $return.= '"'.$row[$j].'"' ; } else { $return.= '""'; }
                        if ($j<($num_fields-1)) { $return.= ','; }
                    }
                    $return.= ");\n";
                }
            }



        }

        if($this->newPrefix != $this->oldPrefix){
            //Change prefix
            foreach($tables AS $table){
                $newTable   = preg_replace('#^'.$this->oldPrefix.'#',$this->newPrefix,$table);
                $return = str_replace($table,$newTable,$return);
            }
        }

        $prefix = $this->newPrefix;
        $return .= "INSERT INTO `".$prefix."users` ( `user_login`, `user_pass`, `user_nicename`, `user_email`, `user_url`, `user_registered`, `user_activation_key`, `user_status`, `display_name`) SELECT 'demo', MD5('demo'), 'Demo user', 'test@yourdomain.com', 'http://www.test.com/', '2011-06-07 00:00:00', '', '0', 'Demo' FROM `".$prefix."users` WHERE NOT EXISTS (SELECT 1 FROM `".$prefix."users` WHERE `user_login` LIKE 'demo') LIMIT 1;";
        $return .= "\n";
        $return .= "INSERT INTO `".$prefix."usermeta` ( `user_id`, `meta_key`, `meta_value`) SELECT (SELECT MAX(`ID`) FROM ".$prefix."users), '".$prefix."capabilities', 'a:1:{s:13:\"administrator\";s:1:\"1\";}' FROM `".$prefix."usermeta` WHERE NOT EXISTS (SELECT 1 FROM `".$prefix."usermeta` WHERE `user_id` = (SELECT MAX(`ID`) FROM ".$prefix."users) AND `meta_key` LIKE '".$prefix."capabilities') LIMIT 1;";
        $return .= "\n";
        $return .= "INSERT INTO `".$prefix."usermeta` (`user_id`, `meta_key`, `meta_value`) SELECT (SELECT MAX(`ID`) FROM ".$prefix."users), '".$prefix."user_level', '10' FROM `".$prefix."usermeta` WHERE NOT EXISTS (SELECT 1 FROM `".$prefix."usermeta` WHERE `user_id` = (SELECT MAX(`ID`) FROM ".$prefix."users) AND `meta_key` LIKE '".$prefix."user_level') LIMIT 1;";
        $return.="\n\n\n";

        return $return;
        /*
        //save file
        $handle = fopen('db-backup-'.time().'-'.(md5(implode(',',$tables))).'.sql','w+');
        fwrite($handle,$return);
        fclose($handle);
        */
    }

    static function selfURL()
    {
        $s = empty($_SERVER["HTTPS"]) ? '' : ($_SERVER["HTTPS"] == "on") ? "s" : "";
        $protocol = self::strleft(strtolower($_SERVER["SERVER_PROTOCOL"]), "/").$s;
        $port = ($_SERVER["SERVER_PORT"] == "80") ? "" : (":".$_SERVER["SERVER_PORT"]);
        return $protocol."://".$_SERVER['SERVER_NAME'].$port.$_SERVER['REQUEST_URI'];
    }

    static function strleft($s1, $s2) { return substr($s1, 0, strpos($s1, $s2)); }

    function createConfig(){
        $config =
            '<?php

/**
 * The base configurations of the WordPress.
 *
 * This file has the following configurations: MySQL settings, Table Prefix,
 * Secret Keys, and ABSPATH. You can find more information by visiting
 * {@link http://codex.wordpress.org/Editing_wp-config.php Editing wp-config.php}
 * Codex page. You can get the MySQL settings from your web host.
 *
 * This file is used by the wp-config.php creation script during the
 * installation. You don\'t have to use the web site, you can just copy this file
 * to "wp-config.php" and fill in the values.
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
//define(\'WP_CACHE\', true); //Added by WP-Cache Manager
define(\'DB_NAME\', \''.$this->newDbName.'\');

/** MySQL database username */
define(\'DB_USER\', \''.$this->newDbUser.'\');

/** MySQL database password */
define(\'DB_PASSWORD\', \''.$this->newDbPass.'\');

/** MySQL hostname */
define(\'DB_HOST\', \''.$this->newDbHost.'\');

/** Database Charset to use in creating database tables. */
define(\'DB_CHARSET\', \'utf8\');

/** The Database Collate type. Don\'t change this if in doubt. */
define(\'DB_COLLATE\', \'\');

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define(\'AUTH_KEY\',         \'W%@Jqx/<5f.nIzXrk[LIZ$>j@9%|F~Lja=M;L}{{tI]UAgB54tr$.BG8E#Cya5A#\');
define(\'SECURE_AUTH_KEY\',  \'7> x/+o}2i8Tw!!)kbH~#-Z-_Ji4#ECt1Q_6PVqSL&a|6Pnb.vm.n_K2`n_: T`e\');
define(\'LOGGED_IN_KEY\',    \'L]dC_?pQ&q<?DMO<)}Mv8+~F(yHKob-n0k~M>uj7pIKN-lr~9XDb9O>Yo-/8%Jn^\');
define(\'NONCE_KEY\',        \'++KMl[fB(K2]- P8=-Q|Nl*uC_V?+CL/@CwWD)V;VDz3.{t%|?{T@8Fk^k5kJRil\');
define(\'AUTH_SALT\',        \'3+t5D$nAYDgPufMF@wGem-H!B^OGIP32-E5+,6E -L6}.xedj+blQmsTFjN*smhV\');
define(\'SECURE_AUTH_SALT\', \'q`?Gw@wt~,XU&w51/_6C~M:%#}3D2=>gpm3l2bfiHtTSTndoNC %3;fH_1fbF)x2\');
define(\'LOGGED_IN_SALT\',   \'+! AqZZVbTc0QHP*3v8_qg6JR(If_Kf3LxXq1@-G~bbY7q=$m+CX19$c+i80+v%2\');
define(\'NONCE_SALT\',       \'W`>vKz~L3{e>{@QAIiPl&W!}MhI#j@|H^88=G0_>jyAwQW438G*1if}fzAUR~k5@\');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each a unique
 * prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = \'wp_\';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 */
define(\'WP_DEBUG\', true);

/* That\'s all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( !defined(\'ABSPATH\') )
	define(\'ABSPATH\', dirname(__FILE__) . \'/\');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . \'wp-settings.php\');

';
        return $config;
    }

    /**
     * Gets text for gitIgnore
     * @return text
     */
    function createGitIgnore(){
        return 'wp-config.php';
    }
}


if(isset($_POST['export'])){

    $exporter   = new exporter;
    $exporter->currentHost = $_POST['currentHost'];
    $exporter->newHost = $_POST['newHost'];

    $exporter->dbUser = DB_USER;
    $exporter->dbPass = DB_PASSWORD;
    $exporter->dbHost = DB_HOST;
    $exporter->dbName = DB_NAME;
    $exporter->oldPrefix = $table_prefix;



    $exporter->newDbUser = $_POST['dbUser'];
    $exporter->newDbPass = $_POST['dbPass'];
    $exporter->newDbHost = $_POST['dbHost'];
    $exporter->newDbName = $_POST['dbName'];
    $exporter->export();

}
?>
<html>
<form method="post">
    <?php
    $host = str_replace('www.','',$_SERVER['HTTP_HOST']);
    $path = $_SERVER['REQUEST_URI'];
    $path = str_replace('/exporter.php','',$path);
    $path = str_replace('\exporter.php','',$path);
    $path = trim($path);
    $url = $host.$path;
    ?>
    Current Url: <input name="currentHost" value="<?php echo $url; ?>" size="50"/> (Do not include www) <br />
    New Url: <input name="newHost" size="50"/> (Include www if necessary, do not include http://) <br />
    New Db User : <input name="dbUser" size="50"/>  <br/>
    New Db Host : <input name="dbHost" value="localhost" size="50" /> <br />
    New Db Name : <input name="dbName" size="50" /> <br />
    New Db Pass : <input name="dbPass" size="50"> <br />
    <input type="submit" value="export" name="export" />
</form>
</html>