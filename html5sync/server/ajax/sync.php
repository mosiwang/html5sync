<?php
session_start();
include_once '../core/Html5Sync.php';
include_once '../core/User.php';

//Toma los datos de usuario y rol de la aplicación
$user=new User(intval($_SESSION['html5sync_userId']),$_SESSION['html5sync_role']);

//print_r($user);


//Realiza la conexión y configuración para el usuario actual
$html5sync=new Html5Sync($user);

$json='{"state":"true",';
//Detecta su hubo cambios en la estructura de alguna tabla
if($html5sync->checkIfStructureChanged()){
    $json.='"changesInStructure":"true",';
}else{
    $json.='"changesInStructure":"false",';
}
//Detecta si hubo cambios en los datos
if($html5sync->checkIfDataChanged()){
    $json.='"changesInData":"true"';
}else{
    $json.='"changesInData":"false"';
}
$json.='}';

//Se retorna la respuesta en JSON
echo $json;