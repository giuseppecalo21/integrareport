<?php
define('ABSPATH', sys_get_temp_dir() . '/');
define('IR_DIR', dirname(__DIR__) . '/');
$GLOBALS['opts'] = ['ir_studio' => 'Integra Consulting', 'ir_sito' => 'www.integra.example', 'ir_consulente' => 'Dott. Mario Esempio — Dottore Commercialista'];
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_date($f, $ts = null) { return date($f, $ts ?? time()); }
function wp_rand() { return mt_rand(); }
function sanitize_file_name($s) { return preg_replace('/[^A-Za-z0-9._-]/', '_', $s); }
function wp_tempnam($s) { return tempnam(sys_get_temp_dir(), 'ir'); }
function wp_get_current_user() { return new class { public $display_name = 'Tester'; function exists() { return true; } }; }
function wp_upload_dir() { return ['basedir' => sys_get_temp_dir() . '/ir-test-uploads']; }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true); }
function trailingslashit($s){return rtrim($s,'/').'/';}
function get_userdata($i){return null;}
function wp_check_filetype($n) { return ['type' => 'application/pdf']; }
foreach (['catalog','params','format','store','text-converter','extractor','dataset','calculator','tables','writer','document','docx'] as $c) require IR_DIR . "includes/class-ir-$c.php";
