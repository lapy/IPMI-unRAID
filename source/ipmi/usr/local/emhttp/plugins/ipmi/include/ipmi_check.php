<?php
/* board info */
$boards = ['ASRock'=>'','ASRockRack'=>'', 'Dell' =>'','Supermicro'=>''];
$board_dmi_manufacturer = ipmi_read_dmi_field(2, 'Manufacturer');
$board_dmi_model = ipmi_read_dmi_field(2, 'Product Name');
$board = ($override == 'disable') ? trim((string)$board_dmi_manufacturer) : $oboard;
$board_model = ($override == 'disable') ? trim((string)$board_dmi_model) : $omodel;
$board_status  = array_key_exists($board, $boards);
$sm_gen = '';
if ($board == "Supermicro") {
    $smboard_model = ($override == 'disable') ? 0 : intval($omodel);
    if ($override == 'disable' && preg_match('/^X(\d{1,2})/i', $board_model, $matches))
        $smboard_model = intval($matches[1]);
    $sm_gen="Gen:$smboard_model";
}
if ($board == "Dell") {
    $board_model = ($override == 'disable') ? trim((string)ipmi_read_dmi_field(1, 'Product Name')) : $omodel;
}
?>
