<?php
// Fixture: bilancio fittizio coerente (2023-2024) + dati di supporto. Valori in euro.
if (!function_exists('cand')) {
function cand($code, $period, $value, $file, $page, $stato = 'rilevato', $aff = 'alta', $scale = 1) {
    static $n = 0; $n++;
    return ['id' => "c$n", 'code' => $code, 'period' => (string)$period, 'raw' => $value / $scale, 'scale' => $scale, 'value' => $value, 'stato' => $stato, 'file_id' => $file, 'slot' => 'B1', 'page' => $page, 'pos' => '', 'quote' => "Voce $code " . number_format($value / $scale, 0, ',', '.'), 'aff' => $aff, 'note' => ''];
}
}
$y24 = ['SP_BI'=>20000,'SP_BII'=>300000,'SP_BIII'=>10000,'SP_CI'=>150000,'SP_CII_TOT'=>400000,'SP_CII_ENTRO'=>380000,'SP_CII_OLTRE'=>20000,'SP_CII_CLIENTI'=>350000,'SP_CIV'=>80000,'SP_D_ATT'=>5000,'SP_TOT_ATT'=>965000,
 'SP_PN'=>300000,'SP_PN_I'=>50000,'SP_PN_IV'=>10000,'SP_PN_VI'=>90000,'SP_PN_VIII'=>50000,'SP_PN_IX'=>100000,'SP_B_FONDI'=>15000,'SP_C_TFR'=>60000,'SP_D_TOT'=>580000,'SP_D_ENTRO'=>400000,'SP_D_OLTRE'=>180000,
 'SP_D4_ENTRO'=>100000,'SP_D4_OLTRE'=>150000,'SP_D5_ENTRO'=>0,'SP_D5_OLTRE'=>30000,'SP_D7'=>220000,'SP_D12'=>30000,'SP_D13'=>20000,'SP_E_PASS'=>10000,'SP_TOT_PASS'=>965000,
 'CE_A1'=>1500000,'CE_A5'=>20000,'CE_A_TOT'=>1520000,'CE_B6'=>700000,'CE_B7'=>300000,'CE_B8'=>50000,'CE_B9'=>250000,'CE_B10'=>40000,'CE_B11'=>-10000,'CE_B14'=>20000,'CE_B_TOT'=>1350000,
 'CE_C16'=>1000,'CE_C17'=>20000,'CE_C_TOT'=>-19000,'CE_EBT'=>151000,'CE_20'=>51000,'CE_21'=>100000];
$y23 = ['SP_BI'=>25000,'SP_BII'=>280000,'SP_BIII'=>10000,'SP_CI'=>140000,'SP_CII_TOT'=>360000,'SP_CII_ENTRO'=>345000,'SP_CII_OLTRE'=>15000,'SP_CII_CLIENTI'=>320000,'SP_CIV'=>60000,'SP_D_ATT'=>5000,'SP_TOT_ATT'=>880000,
 'SP_PN'=>200000,'SP_PN_I'=>50000,'SP_PN_IV'=>5000,'SP_PN_VI'=>45000,'SP_PN_VIII'=>50000,'SP_PN_IX'=>50000,'SP_B_FONDI'=>15000,'SP_C_TFR'=>55000,'SP_D_TOT'=>600000,'SP_D_ENTRO'=>420000,'SP_D_OLTRE'=>180000,
 'SP_D4_ENTRO'=>110000,'SP_D4_OLTRE'=>170000,'SP_D5_ENTRO'=>0,'SP_D5_OLTRE'=>10000,'SP_D7'=>230000,'SP_E_PASS'=>10000,'SP_TOT_PASS'=>880000,
 'CE_A1'=>1350000,'CE_A5'=>10000,'CE_A_TOT'=>1360000,'CE_B6'=>650000,'CE_B7'=>280000,'CE_B8'=>45000,'CE_B9'=>230000,'CE_B10'=>35000,'CE_B14'=>15000,'CE_B_TOT'=>1255000,
 'CE_C16'=>500,'CE_C17'=>22000,'CE_C_TOT'=>-21500,'CE_EBT'=>83500,'CE_20'=>33500,'CE_21'=>50000];
$absent = ['SP_A','SP_CIII','SP_D1','SP_D2','SP_D3','SP_PN_V','CE_A2','CE_A3','CE_A4','CE_B12','CE_B13','CE_C15','CE_C17BIS','CE_D_TOT'];
$c = [];
foreach ($y24 as $k=>$v) $c[] = cand($k, 2024, $v, 'f24', 3);
foreach ($y23 as $k=>$v) $c[] = cand($k, 2023, $v, 'f23', 3);
// il bilancio 2024 riporta anche il 2023 come comparativo: conferme concordi
foreach (['SP_PN','CE_A1','SP_TOT_ATT'] as $k) $c[] = cand($k, 2023, $y23[$k], 'f24', 3);
foreach ($absent as $k) { $c[] = cand($k, 2024, 0, 'f24', 3, 'assente'); $c[] = cand($k, 2023, 0, 'f23', 3, 'assente'); }
$c[] = cand('CE_B11', 2023, 0, 'f23', 4, 'assente');
// dati extra
$x = function($code,$p,$v,$file,$slot) use (&$c) { $k = cand($code,$p,$v,$file,1); $k['slot']=$slot; $c[] = $k; };
foreach (['2025-05'=>[500000,380000,0],'2025-06'=>[500000,420000,5000]] as $m=>[$a,$u,$s]) { $x('CR_ACC_TOT',$m,$a,'fcr','C1'); $x('CR_UTI_TOT',$m,$u,'fcr','C1'); $x('CR_SCONFINO',$m,$s,'fcr','C1'); $x('CR_ACC_REVOCA',$m,200000,'fcr','C1'); $x('CR_UTI_REVOCA',$m,190000,'fcr','C1'); }
$x('AGING_FOR_TOT','2025-06-30',240000,'fag','D5'); $x('AGING_FOR_NON_SCADUTO','2025-06-30',150000,'fag','D5'); $x('AGING_FOR_SCADUTO','2025-06-30',90000,'fag','D5'); $x('AGING_FOR_SCADUTO_90','2025-06-30',40000,'fag','D5');
$x('CCII_INPS_SCADUTO_90','2025-06-30',20000,'finps','D3'); $x('CCII_INPS_DOVUTI_ANNO_PREC','2024',60000,'finps','D3');
$x('PROSP_EBITDA','2025',230000,'fbud','F1'); $x('PROSP_IMPOSTE','2025',50000,'fbud','F1'); $x('PROSP_DELTA_CCN','2025',20000,'fbud','F1');
$x('FIN_QUOTA_12M','2025-06-30',100000,'fpia','C3'); $x('FIN_INTERESSI_12M','2025-06-30',18000,'fpia','C3'); $x('FIN_RESIDUO_TOT','2025-06-30',280000,'fpia','C3');
$x('INV_TOTALE','2025',800000,'finv','G3'); $x('INV_IMMOBILI','2025',300000,'finv','G3');
return $c;
