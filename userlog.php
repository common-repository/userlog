<?php

/*
Plugin Name: userlog
Description: Keeps a log of users who logged in from where and when
Version: 1.4
Author: williewonka
Copyright 2013  williewonka  (email : williewonka341@gmail.com)
*/

/* This plugin uses GeoLite Country from MaxMind (http://www.maxmind.com) which is available under terms of GPL/LGPL */

register_activation_hook(__file__, "userlog_init");
//register_deactivation_hook(__file__, 'userlog_deac');
register_uninstall_hook(__file__, 'userlog_uninstall');
add_action('wp_login', 'userlog_loguser', 10, 1);
add_action('admin_menu', 'userlog_dashboard_addpage');
add_action('admin_init', 'userlog_check_admin_input');
add_action('admin_init', 'userlog_register_options');
add_action('init', 'userlog_version_check');
add_action ('init', 'userlog_checkupdatedb');

define ('USERLOG_VERSION', '1.4');

$geodbfile = WP_PLUGIN_DIR . "/" . dirname ( plugin_basename ( __FILE__ ) ) . "/GeoIP.dat";
$geodb6file = WP_PLUGIN_DIR . "/" . dirname ( plugin_basename ( __FILE__ ) ) . "/GeoIPv6.dat";

function userlog_get_country($ip_address) //gets the country code from the database
{
    global $geodbfile,$geodb6file;
	include_once("geoip.inc");
	$ipv4 = FALSE;
	$ipv6 = FALSE;
	if (userlog_is_valid_ipv4($ip_address)) { $ipv4 = TRUE; }
	if (userlog_is_valid_ipv6($ip_address)) { $ipv6 = TRUE; }
	
	if ($ipv4) 
	{ 	
		$gi = geoip_open ( $geodbfile, GEOIP_STANDARD );
		$country = geoip_country_name_by_addr ( $gi, $ip_address );
		geoip_close ( $gi );
	}
	elseif ($ipv6)
	{
		if (file_exists ( $geodb6file )) {				
			$gi = geoip_open($geodb6file,GEOIP_STANDARD);
			$country = geoip_country_name_by_addr_v6 ( $gi, $ip_address );
 			geoip_close($gi);
		}
		else {
			$country = 'ipv6';				
		}
	}
    
    return $country;
}

function userlog_checkupdatedb() //checks if there is need for an autoupdate of the database
{
    $time = get_option('userlog_databaselastupdate') + 60 * 60 * 24 * 30;
    if(time() > $time)
    {
        userlog_downloadgeodatabase("4", false);
        userlog_downloadgeodatabase("6", false);
    }
}

function userlog_version_check() //checks if the database structure has the most up to date structure to prevent the need for deactivating/reactivating the plugin after a change in a plugin update
{
    if(!get_option('userlog_version') || get_option('userlog_version') != USERLOG_VERSION)
    {
        userlog_init();
    }
}
function userlog_check_admin_input() //checks if the admin has given any input on the dashboard and acts accordingly
{
    if(current_user_can('manage_options') && isset($_POST['empty']))
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'userlog';
        $sql = "TRUNCATE TABLE $table_name";
        $wpdb->query($sql);
        ?>
<script type="text/javascript">window.alert("Logs emptied")</script>
        <?php
    }
}

function userlog_dashboard_addpage() //adds page to the dashboard
{
    add_menu_page("Userlog", "Userlog", 'manage_options', 'userlog', 'userlog_dashboard', null, 81);
    add_submenu_page("userlog", "Userlog options", "Options", 'manage_options', 'userlog_options', 'userlog_dashboard_options');
}

function userlog_register_options() //registers the options for the admin dashboard api
{
    add_settings_section('userlog_options', 'userlog', 'userlog_optionmenu_text', 'userlog');
    register_setting('userlog_options', 'userlog_timezone');
    add_settings_field('timezone', 'The timezone in wich the login time will be displayed in the logs: ', 'userlog_option_timezone', 'userlog', 'userlog_options');
}

function userlog_dashboard_options() //builds the options menu itself
{
    if(isset($_GET['settings-updated']))
    {
        ?>
<div id="message" class="updated">
<p><strong>Settings saved</strong></p>
</div>
        <?php
    }
    ?>
<div class="wrap">
    <h2>Userlog options</h2>

    <form action="options.php" method="post">
    <?php
        settings_fields('userlog_options');
        do_settings_sections('userlog');
        submit_button("Save Changes");
    ?>
    </form>
</div>
    <?php
}

function userlog_optionmenu_text() //builds the text for the menu
{
    echo '<p>Here you can set some settings for the userlog plugin.</p>';
    echo 'Look <a href="http://php.net/manual/en/timezones.php">here</a> for a list of valid timezones (for example: Europe/Amsterdam)';
}

function userlog_option_timezone() //the form for the timezone option in the option menu
{
    echo '<input id="userlog_timezone" name="userlog_timezone" size="40" type="text" value="' . get_option('userlog_timezone') . '" />';
}

function userlog_dashboard() //the actual page on the dashboard
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'userlog';
    $results = NULL;
    if(isset($_POST['search']))
    {
        global $wpdb;
        $keyword = $wpdb->prepare("%s",$_POST['search']);
        switch($_POST['type'])
        {
            case 'empty':
                $sql = "SELECT user, time, ip FROM $table_name ORDER BY time DESC";
                $results = $wpdb->get_results($sql); 
            break;
            case 'user':
                $sql = "SELECT user, time, ip FROM $table_name WHERE user LIKE'%$keyword%' ORDER BY time DESC";
                $results = $wpdb->get_results($sql); 
            break;
            
            case 'country':
                $sql = "SELECT user, time, ip FROM $table_name ORDER BY time DESC";
                $results = $wpdb->get_results($sql);
                $i = 0;
                $empty = 1;
                foreach($results as $item)
                {
                    $country = userlog_get_country($item->ip);
                    if($country != $keyword)
                    {
                        $results[$i] = NULL;
                    }
                    elseif($country == $keyword)
                    {
                        $empty = 0;
                    }
                    $i++;
                }
                if($empty)
                {
                    $results = NULL;
                }
                
            break;
                        
            case 'ip':
                $sql = "SELECT user, time, ip FROM $table_name WHERE ip LIKE '%$keyword%' ORDER BY time DESC";
                $results = $wpdb->get_results($sql); 
            break;
        }
    }else
    {
       $sql = "SELECT user, time, ip FROM $table_name ORDER BY time DESC";
       $results = $wpdb->get_results($sql); 
    }
    if(isset($_POST['search']))
    {
        ?>
<div id="message" class="updated">
<p><strong>Search completed</strong></p>
</div>
        <?php
    }
    
    if($results == NULL)
    {
        ?>
<div class="wrap">
    <h2>No logs available.</h2>
        <?php
    }else
    {
        
        date_default_timezone_set(get_option('userlog_timezone'));
?>
<div class="wrap">
    <h2>Log of succesfull login attempts</h2>
    <table border="4">
    <tr><td><b>Username</b></td><td><b>Time</b></td><td><b>Ip-Adress</b></td><td><b>Country</b></td></tr>
    <?php
        foreach($results as $data)
        {
            echo '<tr><td>';
            echo $data->user;
            echo '</td><td>';
            echo date('G:i:s D j F', $data->time);
            echo '</td><td>';
            echo '<a href="http://ip-adress.com/ip_tracer/' . $data->ip . '">' . $data->ip . '</a>';
            echo '</td><td>';
            echo userlog_get_country($data->ip);
            echo '</td></tr>';
        }
    ?>
    </table>
    <form action="" method="post">
    <input type="hidden" name="empty" id="empty" value="true">
    <?php submit_button("Emtpy logs");  ?>
    </form>
    <?php
    }
    if(isset($_POST['search']) || !$empty)
    {
    ?>
    <br /><br />
    <h2>Search:</h2>
    <form action="" method="post">
    Search: <input type="text" name="search" id="search" size="40"/>
    <select name="type">
    <option value="empty"></option>
    <option value="user">User</option>
    <option value="country">Country</option>
    <option value="ip">Ipadress</option>
    </select>
    <?php submit_button("Search"); ?>
    </form>
    </div>
    <?php
    }
}

function userlog_loguser($user_login) //does the actuall loging when a user logs in, it runs after wp has finished the logging in proces
{
    global $wpdb;
    $table_name = $wpdb->prefix . "userlog";
    $ip = $_SERVER['REMOTE_ADDR'];
    $time = time();
    //$user = $wpdb->prepare($user_login);
    $sql = $wpdb->prepare("INSERT INTO $table_name VALUES (NULL, '%s', '$time', '$ip')",$user_login);
    $wpdb->query($sql);
}

function userlog_downloadgeodatabase($version, $displayerror) {
/*
 * Download the GeoIP database from MaxMind
 */
 /* GeoLite URL */
	
 if( !class_exists( 'WP_Http' ) )
        include_once( ABSPATH . WPINC. '/class-http.php' );

 global $geodbfile,$geodb6file;
 if ($version == 6)
 {
 	$url = 'http://geolite.maxmind.com/download/geoip/database/GeoIPv6.dat.gz';
 	$geofile = $geodb6file;
 }
 else 
 {
 	$url = 'http://geolite.maxmind.com/download/geoip/database/GeoLiteCountry/GeoIP.dat.gz';
 	$geofile = $geodbfile;
 }       
 
 $request = new WP_Http ();
 $result = $request->request ( $url );
 $content = array ();

 if ((in_array ( '403', $result ['response'] )) && (preg_match('/Rate limited exceeded, please try again in 24 hours./', $result['body'] )) )  {
    if($displayerror){
 ?>
 	<p>Error occured: Could not download the GeoIP database from <?php echo $url;?><br />
	MaxMind has blocked requests from your IP address for 24 hours. Please check again in 24 hours or download this file from your own PC<br />
    unzip this file and upload it (via FTP for instance) to:<br /> <strong><?php echo $geofile;?></strong></p>
 <?php
    }
 }
 elseif ((isset ( $result->errors )) || (! (in_array ( '200', $result ['response'] )))) {
    if($displayerror){
 ?>
 	<p>Error occured: Could not download the GeoIP database from <?php echo $url;?><br />
	Please download this file from your own PC unzip this file and upload it (via FTP for instance) to:<br /> 
	<strong><?php echo $geofile;?></strong></p>
 <?php
    }
 } else {

//	global $geodbfile;
			
	/* Download file */
	if (file_exists ( $geofile . ".gz" )) { unlink ( $geofile . ".gz" ); }
	$content = $result ['body'];
	$fp = fopen ( $geofile . ".gz", "w" );
	fwrite ( $fp, "$content" );
	fclose ( $fp );
		
	/* Unzip this file and throw it away afterwards*/
	$zd = gzopen ( $geofile . ".gz", "r" );
	$buffer = gzread ( $zd, 2000000 );
	gzclose ( $zd );
	if (file_exists ( $geofile . ".gz" )) { unlink ( $geofile . ".gz" ); }
			
	/* Write this file to the GeoIP database file */
	if (file_exists ( $geofile )) { unlink ( $geofile ); } 
	$fp = fopen ( $geofile, "w" );
	fwrite ( $fp, "$buffer" );
	fclose ( $fp );
    if($displayerror){
	   print "<p>Finished downloading</p>";
    }
    update_option('userlog_databaselastupdate' , time());	
 }
 if (! (file_exists ( $geodbfile ))) {
    if($displayerror){
	?> 
	<p>Fatal error: GeoIP <?php echo $geodbfile ?> database does not exists. This plugin will not work until the database file is present.</p>
	<?php
    }
 }
 print "<hr>";
}

function userlog_is_valid_ipv4($ipv4) {

	if(filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
		return false;
	}

	return true;
}

function userlog_is_valid_ipv6($ipv6) {

	if(filter_var($ipv6, FILTER_VALIDATE_IP,FILTER_FLAG_IPV6) === FALSE) {
		return false;
	}

	return true;
}

function userlog_init() //creates a table in the database for the plugin and creates some options
{
    global $wpdb;
    $table_name = $wpdb->prefix . "userlog";
    $sql = "CREATE TABLE $table_name (
	id mediumint(9) NOT NULL AUTO_INCREMENT,
	user text NOT NULL,
	time text NOT NULL,
	ip text NOT NULL,
	UNIQUE KEY id (id)
	);";
    require_once (ABSPATH . 'wp-admin/includes/upgrade.php');

    dbDelta($sql);
    
    add_option('userlog_timezone', 'UTC');
    update_option('userlog_version', USERLOG_VERSION);
    update_option('userlog_databaselastupdate', 0);
    userlog_downloadgeodatabase("4", false);
    userlog_downloadgeodatabase("6", false);
}

function userlog_uninstall() //deletes all the database entries that the plugin has created
{
    global $wpdb;
    $table_name = $wpdb->prefix . "userlog";
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    delete_option('userlog_timezone');
    delete_option('userlog_version');
    delete_option('userlog_databaselastupdate');
}

?>