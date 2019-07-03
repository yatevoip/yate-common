<?php

/* api_logs.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * JSON API logs reader
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2014-2019 Null Team
 *
 * This software is distributed under multiple licenses;
 * see the COPYING file in the main directory for licensing
 * information for this specific distribution.
 *
 * This use of this software may be subject to additional restrictions.
 * See the LEGAL file in the main directory for details.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

@include_once("api_config.php");

session_start();

if (getparam("method")=="logout") {
	session_unset();
}

$logs_file = "/var/log/json_api/*_log*.txt";

$self = $_SERVER["PHP_SELF"];
$self = explode("/",$self);
$self = $self[count($self)-1];
$_SESSION["main"] = $self;

call_func();

function page()
{
	global $logs_file;
	global $r_logs_file;
	global $logs_file_to_view;
	global $node;

	$add = array();

	$use_file = (isset($logs_file_to_view)) ? $r_logs_file : $logs_file;

	$path = explode("/",$use_file);
	$file_name = $path[count($path)-1];
	unset($path[count($path)-1]);
	$path = implode("/",$path);

	$names = array();
	if ($handle = @opendir($path))
	{
		while (false !== ($file = readdir($handle)))
		{
			if (fnmatch($file_name,$file))
				$names[] = $file;
		}
		closedir($handle);
	}

	array_multisort($names, SORT_ASC);
	$today_file = (count($names) > 0) ? $names[count($names)-1] : "";

	$names["selected"] = (getparam("file")) ? getparam("file") : $today_file;
	$add = array("file"=>array($names, "display"=>"select"), "node_type"=>array("value"=>$node, "display"=>"hidden"));

	generic_page("Please select logs file to view", true, $add);
}

function addHidden($action=NULL, $additional = array(), $empty_page_params=false)
{
	global $method,$module;

	if (($method || $empty_page_params) && !isset($additional["method"]))
		print "<input type=\"hidden\" name=\"method\" id=\"method\" value=\"$method\" />\n";

	if (is_array($module) && !isset($additional["module"]))
		print "<input type=\"hidden\" name=\"module\" id=\"module\" value=\"$module[0]\" />\n";
	elseif (($module || $empty_page_params) && !isset($additional["module"]))
		print "<input type=\"hidden\" name=\"module\" id=\"module\" value=\"$module\" />\n";

	print "<input type=\"hidden\" name=\"action\" id=\"action\" value=\"$action\" />\n";

	if (count($additional))
		foreach($additional as $key=>$value) 
			print '<input type="hidden" id="' . $key . '" name="' . $key . '" value="' . $value . '">';

	if (isset($_SESSION["previous_page"])) {
		foreach ($_SESSION["previous_page"] as $param=>$value)
			if (!isset($additional[$param]) && $param!="module" && $param!="method" && $param!="action")
				print '<input type="hidden" name="' . $param . '" value="' . $value . '">';
	}
}


function page_database()
{
	global $logs_file;
	global $r_logs_file;
	global $logs_file_to_view;

	$add = array();

	$use_file = (isset($logs_file_to_view)) ? $r_logs_file : $logs_file;

	if (!check_auth("logs")) {
		print "Error! Please insert password.";
		page();
		return;
	}

	page();

	$path = explode("/",$use_file);
	$file_name = $path[count($path)-1];
	unset($path[count($path)-1]);
	$path = implode("/",$path);

	$file = getparam("file");
	if (!$file) {
		print "Error: Please select file.";
		return;
	}
	$file = "$path/$file";

	$fh = @fopen($file,"r");
	if (!$fh)
		exit("Could not open logs file $file");

	$i=0;
	$log=0;
	print "<html><body style=\"font-size:13px;\">";
	while($line=fgets($fh))
	{
		if (substr($line,0,3)=="---") {
			if ($i)
				print "</div>";
			print "<div ";
			$log++;
			if ($log%2)
				print "style=\"background-color:#ddd;\"";
			print ">";
		}

		print htmlspecialchars($line)."<br/>";
		$i++;
	}
	if ($i)
		print "</div>";
	print "</html></body>";
	fclose($fh);
}

/**
 * function used to check on_page authentication
 * used in debug_all.php
 */ 
function is_auth($identifier)
{
	global ${"pass_".$identifier."_page"};

	if (isset($_SESSION["pass_$identifier"]) || !isset(${"pass_".$identifier."_page"}) || !strlen(${"pass_".$identifier."_page"}))
		return true;
	return false;
}

/**
 *  function used to check on_page authentication/authenticate
 *  used in debug_all.php
 */ 
function check_auth($identifier)
{
	global ${"pass_".$identifier."_page"};

	if (strlen(${"pass_".$identifier."_page"}) && !isset($_SESSION["pass_$identifier"])) {
		$pass = (isset($_REQUEST["pass_$identifier"])) ? $_REQUEST["pass_$identifier"] : '';
		if ($pass != ${"pass_".$identifier."_page"}) 
			return false;
		$_SESSION["pass_$identifier"] = true;
	}
	return true;
}


function generic_page($title, $no_server=false, $additional=NULL)
{
	global $servers, $default_server, $pass_logs_page;

	//$f_servers = array();
	//foreach ($servers as $name=>$info)
	//	$f_servers[] = $name;
	//$f_servers["selected"] = (getparam("server")) ? getparam("server") : $default_server;

	$fields = array(
		"pass_logs" => array("compulsory"=>true, "column_name"=>"Password"),
		//"server" => array($f_servers, "display"=>"select")	
	);

	if (is_array($additional))
		$fields = array_merge($fields,$additional);

	if (is_auth("logs")) {
		unset($fields["pass_logs"]);
		$page = $_SERVER["PHP_SELF"];
		$page = explode("/",$page);
		$page = $page[count($page)-1];
		if (isset($pass_logs_page) && strlen($pass_logs_page))
			print "<a style='float:right;' href='$page?method=logout'>Logout</a>";
	}

	if ($no_server)
		unset($fields["server"]);

	if (count($fields)) {
		print "<br/>";
		start_form();
		addHidden("database", array("server"=>getparam("server")));
		editObject(NULL,$fields,$title);
		end_form();
	}
}


function call_func()
{
	global $limit, $page, $action, $total;

	$limit = getparam("limit");
	if(!$limit)
		$limit = 50;
	$page = getparam("page");
	if (!$page)
		$page = 0;
	$total = getparam("total");

	$action = getparam("action");
	if($action)
		$call = "page_".$action;
	else
		$call = "page";
?>
<html>
<head>
<script type="text/javascript" src="javascript.js"></script>
	<?php include_css(); ?>
</head>
<body>
<?php
	$call();
?>
</body>
</html>
<?php
}

function include_css()
{
?>
<style type="text/css">
/* style for editing an object */
table.edit, table.widder_edit, table.smaller_edit,table.smaller_edit2,table.smaller_edit3
{
        font:12px  Arial, Verdana, Helvetica, sans-serif;
        border:1px solid #0189d7;
        width:450px;
        margin-top:5px;
        background-color:white;

        margin-left:auto;
        margin-right:auto;
}

table.widder_edit
{
        width:530px;
}

table.smaller_edit
{
        width:327px;
}

table.smaller_edit2
{
        width:255px;
}
table.smaller_edit3
{
        width:295px;
}
th.edit,th.widder_edit, th.smaller_edit,th.smaller_edit2,th.smaller_edit3
{
        background-color: #0189d7;/*#104a73;*/
        color: white;
        font-weight:bold;
        padding:3px;
        padding-top:5px;
        padding-bottom:5px;
}

td.edit, td.widder_edit, td.smaller_edit,td.smaller_edit2,td.smaller_edit3
{
        padding-left:5px;
        padding-right:5px;
        padding-top:3px;
        padding-bottom:3px;
        text-align:left;
        border-bottom:1px solid #0189d7;
}

font.compulsory
{
        color: #0189d7;
        font-size:15px;
}

td.left_td
{
        text-align:left;
        width:30%;
        vertical-align:top;
}

td.right_td
{
        text-align:left;
        width:70%;
        vertical-align:top;
}

font.comment
{
        font-size:11px;
}


table.content
{
	font-size:13px;
	/*this is how a table can be centered in css*/
	width:80%;
    margin-left: auto;
    margin-right: auto;
	text-align:center;
}

th.content
{
	border-bottom:1px solid #0189d7;
	padding-left:5px;
	padding-right:5px;
	background-color:#0189d7;
	color:white;
	font-size:13px;
	font-weight:normal;
	border-right:1px solid white;
	padding-top:3px;
	padding-bottom:3px;
}

td.content
{
    text-align:center;
    padding-left:5px;
    padding-right:5px;
	font-weight:normal;
/*	border-bottom:1px solid white;/*#eee;*/
}

td.endtable
{
/*    border-top:1px solid #376EA4;*/
	border-bottom:none;
}
td.evenrow
{
	background-color:#ddd;
}
</style>
<?php
}

function getparam($param,$escape = true)
{
	$ret = NULL;
	if (isset($_POST[$param]))
		$ret = $_POST[$param];
	else if (isset($_GET[$param]))
		$ret = $_GET[$param];
	else
		return NULL;
	return $ret;
}

/**
 * Creates a form for editing an object
 * @param $object Object that will be edited or NULL if fields don't belong to an object
 * @param $fields Array of type field_name=>field_formats
 * Ex: $fields =  array(
		"username"=>array("display"=>"fixed", "compulsory"=>true), 
		// if index 0 in the array is not set then this field will correspond to variable username of @ref $object
		// the field will be marked with a *(compulsory)
		"description"=>array("display"=>"textarea", "comment"=>"short description"), 
		// "comment" is used for inserting a comment under the html element 
		"password"=>array("display"=>"password", "compulsory"=>"yes"), 
		"birthday"=>array("date", "display"=>"include_date"), 
		// will call function include_date
		"category"=>array($categories, "display"=>"select") 
		// $categories is an array like 
		// $categories = array(array("category_id"=>"4", "category"=>"Nature"), array("category_id"=>"5", "category"=>"Movies")); when select category 'Nature' $_POST["category"] will be 4
		// or $categories = array("Nature", "Movies");
		"sex"=>array($sex, "display"=>"radio") 
		// $sex = array("male","female","don't want to answer");
	); 
 * instead of "compulsory", "requited" can be also used
 * possible values for "display" are "textarea", "password", "fileselect", "text", "select", "radio", "radios", "checkbox", "fixed"
 * If not specified display is "text"
 * If the field corresponds to a bool field in the object given display is ignored and display is set to "checkbox"
 * @param $title Text representing the title of the form
 * @param $submit Text representing the value of the submit button or Array of values that will appear as more submit buttons
 * @param $compulsory_notice Bool true for using default notice, Text representing a notice that will be printed under the form if other notice is desired or NULL or false for no notice
 * @param $no_reset When set to true the reset button won't be displayed, Default value is false
 * @param $css Name of the css to be used when generating the elements. Default value is 'edit'
 * @param $form_identifier Text. Used to make the current fields unique(Used when this function is called more than once inside the same form with fields that can have the same name when being displayed)
 * @param $td_width Array or by default NULL. If Array("left"=>$value_left, "right"=>$value_right), force the widths to the ones provided. $value_left could be 20px or 20%.
 * @param $hide_advanced Bool default false. When true advanced fields will be always hidden when displaying form
 */
function editObject($object, $fields, $title, $submit="Submit", $compulsory_notice=NULL, $no_reset=false, $css=NULL, $form_identifier='', $td_width=NULL, $hide_advanced=false)
{
	if(!$css)
		$css = "edit";

	print '<table class="'.$css.'" cellspacing="0" cellpadding="0">';
	if($title) {
		print '<tr class="'.$css.'">';
		print '<th class="'.$css.'" colspan="2">'.$title.'</th>';
		print '</tr>';
	}

	$show_advanced = false;
	$have_advanced = false;
	//find if there are any fields marked as advanced that have a value(if so then all advanced fields should be displayed)
	foreach($fields as $field_name=>$field_format)
	{
		if(!isset($field_format["advanced"]))
			continue;
		if($field_format["advanced"] != true)
			continue;
		$have_advanced = true;
		if($object)
			$value = (!is_array($field_name) && isset($object->{$field_name})) ? $object->{$field_name} : NULL;
		else
			$value = NULL;
		if(isset($field_format["value"]))
			$value = $field_format["value"];
		if (!$object)
			break;
		$variable = $object->variable($field_name);
		if((!$variable && $value && !$hide_advanced))
		{
			$show_advanced = true;
			break;
		}
		if(!$variable)
			continue;
		if (($value && $variable->_type != "bool" && !$hide_advanced) || ($variable->_type == "bool" && $value == "t" && !$hide_advanced))
		{
			$show_advanced = true;
			break;
		}
	}

	//if found errors in advanced fields, display the fields
	foreach($fields as $field_name=>$field_format) {
		if(!isset($field_format["advanced"]))
			continue;
		if (isset($field_format["error"]) && $field_format["error"]===true) {
			$show_advanced = true;
			break;
		}
	}

	foreach($fields as $field_name=>$field_format) 
		display_pair($field_name, $field_format, $object, $form_identifier, $css, $show_advanced, $td_width);
	

	if($have_advanced && !$compulsory_notice)
	{
		print '<tr class="'.$css.'">';
		print '<td class="'.$css.' left_td advanced">&nbsp;</th>';
		print '<td class="'.$css.' left_right advanced"><img id="'.$form_identifier.'xadvanced"';
		if(!$show_advanced)
			print " src=\"images/advanced.jpg\" title=\"Show advanced fields\"";
		else
			print " src=\"images/basic.jpg\" title=\"Hide advanced fields\"";
		print ' onClick="advanced(\''.$form_identifier.'\');"/></th></tr>';
	}
	if($compulsory_notice && $compulsory_notice !== true)
	{
		if($have_advanced) {
		print '<tr class="'.$css.'">';
		print '<td class="'.$css.' left_td" colspan="2">';
		print '<img class="advanced" id="'.$form_identifier.'advanced" ';
		if(!$show_advanced)
			print "src=\"images/advanced.jpg\" title=\"Show advanced fields\"";
		else
			print "src=\"images/basic.jpg\" title=\"Hide advanced fields\"";
		print ' onClick="advanced(\''.$form_identifier.'\');"/>'.$compulsory_notice.'</td>';
		print '</tr>';
		}
	}elseif($compulsory_notice === true){
		print '<tr class="'.$css.'">';
		print '<td class="'.$css.' left_td" colspan="2">';
		if($have_advanced) {
		print '<img id="'.$form_identifier.'xadvanced"';
		if(!$show_advanced)
			print " class=\"advanced\" src=\"images/advanced.jpg\" title=\"Show advanced fields\"";
		else
			print " class=\"advanced\" src=\"images/basic.jpg\" title=\"Hide advanced fields\"";
		print ' onClick="advanced(\''.$form_identifier.'\');"/>';
		}
		print 'Fields marked with <font class="compulsory">*</font> are required.</td>';
		print '</tr>';
	}
	if($submit != "no" && $submit != "no_submit")
	{
		print '<tr class="'.$css.'">';
		print '<td class="'.$css.' trailer" colspan="2">';
		if(is_array($submit))
		{
			for($i=0; $i<count($submit); $i++)
			{
				print '&nbsp;&nbsp;';
				print '<input class="'.$css.'" type="submit" name="'.$submit[$i].'" value="'.$submit[$i].'"/>';
			}
		}else
			print '<input class="'.$css.'" type="submit" name="'.$submit.'" value="'.$submit.'"/>';
		if(!$no_reset) {
			print '&nbsp;&nbsp;<input class="'.$css.'" type="reset" value="Reset"/>';
			$cancel_but = cancel_button($css);
			if ($cancel_but)
				print "&nbsp;&nbsp;$cancel_but";
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';
}

/** 
 * Creates an input cancel button with build onclick link
 * to return to the previous page
 */ 
function cancel_button($css="", $name="Cancel")
{
	$res = null;
	if (isset($_SESSION["previous_page"])) {
		$link = $_SESSION["main"]."?";
		foreach ($_SESSION["previous_page"] as $param=>$value)
			$link.= "$param=".urlencode($value)."&";
		$res = '<input class="'.$css.'" type="button" value="'.$name.'" onClick="location.href=\''.$link.'\'"/>';
	}
	return $res;
}

/**
 * Builds the HTML data for FORM
 */ 
function display_pair($field_name, $field_format, $object, $form_identifier, $css, $show_advanced, $td_width)
{
	$q_mark = false;
	if (isset($field_format["advanced"]))
		$have_advanced = true;

	if (isset($field_format["triggered_by"]))
		$needs_trigger = true;

	if ($object)
		$value = (!is_array($field_name) && isset($object->{$field_name})) ? $object->{$field_name} : NULL;
	else
		$value = NULL;
	if (isset($field_format["value"]))
		$value = $field_format["value"];

	if (!strlen($value) && isset($field_format["cb_for_value"]) && isset($field_format["cb_for_value"]["name"]) && is_callable($field_format["cb_for_value"]["name"])) {
		if (count($field_format["cb_for_value"])==2)
			$value = call_user_func_array($field_format["cb_for_value"]["name"],$field_format["cb_for_value"]["params"]);
		else
			$value = call_user_func($field_format["cb_for_value"]["name"]);
	}

	print '<tr id="tr_'.$form_identifier.$field_name.'"';
//		if($needs_trigger == true)	
//			print 'name="'.$form_identifier.$field_name.'triggered'.$field_format["triggered_by"].'"';

	if (isset($field_format["error"]) && $field_format["error"]===true)
		$css .= " error_field";
	print ' class="'.$css.'"';
	if(isset($field_format["advanced"]))
	{
		if(!$show_advanced) {
			print ' style="display:none;" advanced="true" ';
			if (isset($field_format["triggered_by"]))
				print " trigger=\"true\" ";

		} elseif(isset($field_format["triggered_by"])){
			if($needs_trigger)
				print ' style="display:none;" trigger=\"true\" ';
			else
				print ' style="display:table-row;" trigger=\"true\" ';
		} else
			print ' style="display:table-row;"';
	} elseif (isset($field_format["triggered_by"])) {
		if ($needs_trigger)
			print ' style="display:none;" trigger=\"true\" ';
		else
			print ' style="display:table-row;" trigger=\"true\" ';
	}
	print '>';
	// if $var_name is an array we won't use it
	$var_name = (isset($field_format[0])) ? $field_format[0] : $field_name;
	$display = (isset($field_format["display"])) ? $field_format["display"] : "text";

	if ($object) {
		$variable = (!is_array($var_name)) ? $object->variable($var_name) : NULL;
		if ($variable) {
			if ($variable->_type == "bool" && $display!="text")
				$display = "checkbox";
		}
	}

	if ($display == "message") {
		print '<td class="'.$css.' double_column" colspan="2">';
		print $value;
		print '</td>';
		print '</tr>';
		return;
	}

	if ($display != "hidden") {
		print '<td class="'.$css.' left_td ';
		if (isset($field_format["custom_css_left"]))
			print $field_format["custom_css_left"];
		print '"';
		if (isset($td_width["left"]))
			print ' style="width:'.$td_width["left"].'"';
		print '>';
		if (!isset($field_format["column_name"]))
			print ucfirst(str_replace("_","&nbsp;",$field_name));
		else
			print ucfirst($field_format["column_name"]);
		if (isset($field_format["required"]))
			$field_format["compulsory"] = $field_format["required"];
		if (isset($field_format["compulsory"]))
			if($field_format["compulsory"] === true || $field_format["compulsory"] == "yes" || $field_format["compulsory"] == "t" || $field_format["compulsory"] == "true")
				print '<font class="compulsory">*</font>';
		print '&nbsp;</td>';
		print '<td class="'.$css.' right_td ';
		if (isset($field_format["custom_css_right"]))
			print $field_format["custom_css_right"];
		print '"';
		if (isset($td_width["right"]))
			print ' style="width:'.$td_width["right"].'"';
		print '>';
	}
	switch($display) {
		case "textarea":
			print '<textarea class="'.$css.'" name="'.$form_identifier.$field_name.'" cols="20" rows="5">';
			print $value;
			print '</textarea>';
			break;
		case "select":
		case "mul_select":
		case "select_without_non_selected":
			print '<select class="'.$css.'" name="'.$form_identifier.$field_name.'" id="'.$form_identifier.$field_name.'" ';
			if (isset($field_format["javascript"]))
				print $field_format["javascript"];
			if ($display == "mul_select")
				print ' multiple="multiple" size="5"';
			print '>';
			if ($display != "mul_select" && $display != "select_without_non_selected")
				print '<option value="">Not selected</option>';

			// PREVIOUS implementation when only 0 key could be used for dropdown options
			// $options = (is_array($var_name)) ? $var_name : array();

			// try gettting it from value
			if ($value && is_array($value))
				$options = $value;
			elseif (is_array($var_name))
				$options = $var_name;
			else
				$options = array();

			if (isset($field_format["selected"]))
				$selected = $field_format["selected"];
			elseif (isset($options["selected"]))
				$selected = $options["selected"];
			elseif (isset($options["SELECTED"]))
				$selected = $options["SELECTED"];
			else
				$selected = '';
			foreach ($options as $var=>$opt) {
				if ($var === "selected" || $var === "SELECTED")
					continue;
				$css = (is_array($opt) && isset($opt["css"])) ? 'class="'.$opt["css"].'"' : "";
				if (is_array($opt) && isset($opt[$field_name.'_id'])) {
					$optval = $field_name.'_id';
					$name = $field_name;

					$printed = trim($opt[$name]);
					if (substr($printed,0,4) == "<img") {
						$expl = explode(">",$printed);
						$printed = $expl[1];
						$jquery_title = " title=\"".str_replace("<img","",$expl[0])."\"";
					} else
						$jquery_title = '';

					if ($opt[$optval] === $selected || (is_array($selected) && in_array($opt[$optval],$selected))) {
						print '<option value=\''.$opt[$optval].'\' '.$css.' SELECTED ';
						if($opt[$optval] == "__disabled")
							print ' disabled="disabled"';
						print $jquery_title;
						print '>' . $printed . '</option>';
					} else {
						print '<option value=\''.$opt[$optval].'\' '.$css;
						if($opt[$optval] == "__disabled")
							print ' disabled="disabled"';
						print $jquery_title;
						print '>' . $printed . '</option>';
					}
				} else {
					if (($opt == $selected && strlen($opt)==strlen($selected)) ||  (is_array($selected) && in_array($opt,$selected)))
						print '<option '.$css.' SELECTED >' . $opt . '</option>';
					else
						print '<option '.$css.'>' . $opt . '</option>';
				}
			}
			print '</select>';
			if(isset($field_format["add_custom"]))
				print $field_format["add_custom"];

			break;
		case "radios":
		case "radio":
			$options = (is_array($var_name)) ? $var_name : array();
			if (isset($options["selected"]))
				$selected = $options["selected"];
			elseif (isset($options["SELECTED"]))
				$selected = $options["SELECTED"];
			else
				$selected = "";
			foreach ($options as $var=>$opt) {
				if ($var === "selected" || $var === "SELECTED")
					continue;
				if (count($opt) == 2) {
					$optval = $field_name.'_id';
					$name = $field_name;
					$value = $opt[$optval];
					$name = $opt[$name];
				} else {
					$value = $opt;
					$name = $opt;
				}
				print '<input class="'.$css.'" type="radio" name="'.$form_identifier.$field_name.'" id="'.$form_identifier.$field_name.'" value=\''.$value.'\'';
				if ($value == $selected)
					print ' CHECKED ';
				if (isset($field_format["javascript"]))
					print $field_format["javascript"];
				print '>' . $name . '&nbsp;&nbsp;';
			}
			break;
		case "checkbox":
		case "checkbox-readonly":
			print '<input class="'.$css.'" type="checkbox" name="'.$form_identifier.$field_name.'" id="'.$form_identifier.$field_name.'"';
			if ($value == "t" || $value == "on" || $value=="1")
				print " CHECKED ";
			if (isset($field_format["javascript"]))
				print $field_format["javascript"];
			if ($display=="checkbox-readonly")
				print " disabled=''";
			print '/>';
			break;
		case "text":
		case "password":
		case "file":
		case "hidden":
		case "text-nonedit":
			print '<input class="'.$css.'" type="'.$display.'" name="'.$form_identifier.$field_name.'" id="'.$form_identifier.$field_name.'"';
			if ($display != "file" && $display != "password") {
				if (!is_array($value))
					print ' value="'.$value.'"';
				else {
					if (isset($field_format["selected"]))
						$selected = $field_format["selected"];
					elseif (isset($value["selected"]))
						$selected = $value["selected"];
					elseif (isset($value["SELECTED"]))
						$selected = $value["SELECTED"];
					else
						$selected = '';
					print ' value="'.$selected.'"';
				}
			}
			if (isset($field_format["javascript"]))
				print $field_format["javascript"];
			if ($display == "text-nonedit")
				print " readonly=''";
			if (isset($field_format["autocomplete"]))
				print " autocomplete=\"".$field_format["autocomplete"]."\"";
			if ($display != "hidden" && isset($field_format["comment"])) {
				$q_mark = true;
				if (is_file("images/question.jpg"))
					print '>&nbsp;&nbsp;<img class="pointer" src="images/question.jpg" onClick="show_hide_comment(\''.$form_identifier.$field_name.'\');"/>';
				else
					print '>&nbsp;&nbsp;<font style="cursor:pointer;" onClick="show_hide_comment(\''.$form_identifier.$field_name.'\');"> ? </font>';
			} else
				print '>';
			if($display == 'file' && isset($field_format["file_example"]) && $field_format["file_example"] != "__no_example")
				print '<br/><br/>Example: <a class="'.$css.'" href="download.php?file='.$field_format["file_example"].'">'.$field_format["file_example"].'</a><br/><br/>';
			if($display == 'file' && !isset($field_format["file_example"])) 
				print "For input type file a file example must be given as parameter.";
			break;
		case "fixed":
			if (strlen($value))
				print $value;
			else
				print "&nbsp;";
			break;
		default:
			// make sure the function that displays the advanced field is included
			if (isset($field_format["advanced"]))
				print '<input type="hidden" name="'.$form_identifier.$field_name.'">';

			if (!is_callable($display))
				 print "Callable ".print_r($display,true)." is not implemented.";

			// callback here
			$value = call_user_func_array($display, array($value,$form_identifier.$field_name)); 
			if ($value)
				print $value;
	}
	if ($display != "hidden") {
		if (isset($field_format["comment"])) {
			$comment = $field_format["comment"];

			if (!$q_mark) {
				if (is_file("images/question.jpg"))
					print '&nbsp;&nbsp;<img class="pointer" src="images/question.jpg" onClick="show_hide_comment(\''.$form_identifier.$field_name.'\');"/>';
				else
					print '&nbsp;&nbsp;<font style="cursor:pointer;" onClick="show_hide_comment(\''.$form_identifier.$field_name.'\');"> ? </font>';
			}

			print '<font class="comment" style="display:none;" id="comment_'.$form_identifier.$field_name.'">'.$comment.'</font>';
		}
		print '</td>';
	}
	print '</tr>';
}

/**
 * Builds the HTML <form> tag with all possible attributes:
 * @param $action String. The action of the FORM
 * @param $method String. Allowed values: post|get. Defaults to 'post'.
 * @param $allow_upload Bool. If true allow the upload of files. Defaults to false.
 * @param $form_name String. Fill the attribute name of the FORM. 
 * Defaults to global variable $module or 'current_form' if $module is not set or null
 * @param $class String. Fill the attribute class. No default value set.
 */ 
function start_form($action = NULL, $method = "post", $allow_upload = false, $form_name = NULL, $class = NULL)
{

	global $module;

	if (!$method)
		$method = "post";
	$form = (!$module) ? "current_form" : $module;
	if (!$form_name)
		$form_name = $form;
	if (!$action) {
		if (isset($_SESSION["main"]))
			$action = $_SESSION["main"];
		else
			$action = "index.php";
	}

	?><form action="<?php print $action;?>" name="<?php print $form_name;?>" id="<?php print $form_name;?>" <?php if ($class) print "class=\"$class\"";?> method="<?php print $method;?>" <?php if($allow_upload) print 'enctype="multipart/form-data"';?>><?php
}

/**
 * Ends a HTML FORM tag.
 */
function end_form()
{
	?></form><?php
}

/* vi: set ts=8 sw=4 sts=4 noet: */
?>
