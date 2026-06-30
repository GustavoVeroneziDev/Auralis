<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); exit; }
require_once '../config/conexao.php';

$uid = $_SESSION['usuario_id'];

$allowed = [
    'skinColor'       => ['614335','ae5d29','d08b5b','edb98a','f8d25c','ffdbb4','fd9841','ffffff'],
    'hair'            => ['shortHairShortCurly','shortHairShortFlat','shortHairShortWaved','shortHairShortRound',
                          'shortHairSides','shortHairTheCaesar','shortHairTheCaesarSidePart','shortHairFrizzle',
                          'shortHairShaggyMullet','shortHairDreads01','shortHairDreads02',
                          'longHairBob','longHairBun','longHairCurly','longHairCurvy','longHairStraight',
                          'longHairStraight2','longHairStraightStrand','longHairFro','longHairFroBand',
                          'longHairBigHair','longHairDreads','longHairFrida','longHairMiaWallace',
                          'longHairNotTooLong','longHairShavedSides','noHair','eyepatch',
                          'hat','hijab','turban','winterHat1','winterHat2','winterHat3','winterHat4'],
    'hairColor'       => ['2c1b18','4a312c','b58143','c93305','e8e1e1','f59797','724133','a55728','d6b370','ecdcbf'],
    'eyes'            => ['close','cry','default','dizzy','eyeRoll','happy','hearts','side','squint','surprised','wink','winkWacky'],
    'eyebrows'        => ['angry','angryNatural','default','defaultNatural','flatNatural','raisedExcited',
                          'raisedExcitedNatural','sadConcerned','sadConcernedNatural','unibrowNatural','upDown','upDownNatural'],
    'mouth'           => ['concerned','default','disbelief','eating','grimace','sad','screamOpen','serious','smile','tongue','twinkle','vomit'],
    'clothing'        => ['blazerShirt','blazerSweater','collarSweater','graphicShirt','hoodie','overall','shirtCrewNeck','shirtScoopNeck','shirtVNeck'],
    'clothingColor'   => ['262e33','3c4f5c','65c9ff','929598','a7ffc4','b1e2ff','e6e6e6','ff5c5c','ff488e','ffafb9','ffd670','ffffed'],
    'accessories'     => ['','kurt','prescription01','prescription02','round','sunglasses','wayfarers'],
    'facialHair'      => ['','beardLight','beardMagestic','beardMedium','moustacheFancy','moustacheMagnum'],
    'facialHairColor' => ['2c1b18','4a312c','b58143','c93305','e8e1e1','f59797','724133','a55728','d6b370','ecdcbf'],
    'backgroundColor' => ['b6e3f4','c0aede','d1d4f9','ffd5dc','ffeba4','transparent'],
];

$config = ['style' => 'avataaars'];
foreach ($allowed as $key => $values) {
    $val = $_POST[$key] ?? '';
    $config[$key] = in_array($val, $values, true) ? $val : ($values[0] ?? '');
}

$json = json_encode($config);
$stmt = $pdo->prepare("UPDATE Usuario SET FotoPerfil = :fp WHERE IDUsuario = :uid");
$stmt->execute([':fp' => $json, ':uid' => $uid]);

$_SESSION['avatar_url'] = null; // força reload no próximo acesso

header("Location: /usuario/avatar.php?sucesso=1");
exit;
