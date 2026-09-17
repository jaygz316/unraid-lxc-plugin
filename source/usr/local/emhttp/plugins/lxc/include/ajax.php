<?php
require_once 'functions.php';
require_once 'Settings.php';
require_once 'Container.php';

function sanitizeContainerName($name) {
  $clean = basename(trim((string)$name));
  if (preg_match('/^[a-zA-Z0-9_.-]+$/', $clean)) {
    return $clean;
  }
  return null;
}

if (isset($_POST['lxc'])) {
  $settings = new Settings();
  $action = $_POST['action'] ?? '';
  switch ($action) {
    case 'startCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->startContainer();
      }
      break;
    case 'stopCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->stopContainer();
      }
      break;
    case 'startALLCONT':
      $allContainers = getAllContainers();
      foreach ($allContainers as $cont) {
        if ($cont->state == "STOPPED" || $cont->state == "FROZEN") {
          $container = new Container($cont->name);
          $container->startContainer();
        }
      }
      break;
    case 'stopALLCONT':
      $allContainers = getAllContainers();
      foreach ($allContainers as $cont) {
        if ($cont->state == "RUNNING" || $cont->state == "FROZEN") {
          $container = new Container($cont->name);
          $container->stopContainer();
        }
      }
      break;
    case 'restartCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->restartContainer();
      }
      break;
    case 'freezeCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->freezeContainer();
      }
      break;
    case 'freezeALLCONT':
      $allContainers = getAllContainers();
      foreach ($allContainers as $cont) {
        if ($cont->state == "RUNNING") {
          $container = new Container($cont->name);
          $container->freezeContainer();
        }
      }
      break;
    case 'unfreezeCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->unfreezeContainer();
      }
      break;
    case 'unfreezeALLCONT':
      $allContainers = getAllContainers();
      foreach ($allContainers as $cont) {
        if ($cont->state == "FROZEN") {
          $container = new Container($cont->name);
          $container->unfreezeContainer();
        }
      }
      break;
    case 'killCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->killContainer();
      }
      break;
    case 'autostart':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $autostart = (isset($_POST['autostart']) && ($_POST['autostart'] === 'true' || $_POST['autostart'] === '1' || $_POST['autostart'] === 1)) ? 1 : 0;
        $container->setAutostart($autostart);
      }
      break;
    case 'destroyCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->destroyContainer();
      }
      break;
    case 'snapshotCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->createSnapshot();
      }
      break;
    case 'deleteSNAP':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      $snapshot = basename(trim((string)($_POST['snapshot'] ?? '')));
      if ($cont !== null && preg_match('/^[a-zA-Z0-9_.-]+$/', $snapshot)) {
        $container = new Container($cont);
        $container->deleteSnapshot($snapshot);
      }
      break;
    case 'backupCONT':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->createBackup();
      }
      break;
    case 'deleteBACKUP':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      $backup = basename(trim((string)($_POST['backup'] ?? '')));
      if ($cont !== null && preg_match('/^[a-zA-Z0-9_.-]+$/', $backup)) {
        $container = new Container($cont);
        $container->deleteBackup($backup);
      }
      break;
    case 'createCONT':
      $name = sanitizeContainerName($_POST['name'] ?? '');
      if ($name !== null) {
        createContainer($name, $_POST['description'] ?? '', $_POST['distribution'] ?? '', $_POST['release'] ?? '', $_POST['startcont'] ?? '', $_POST['autostart'] ?? '', $_POST['mac'] ?? '');
      }
      break;
    case 'copyCONT':
      $name = sanitizeContainerName($_POST['name'] ?? '');
      $source = sanitizeContainerName($_POST['container'] ?? '');
      if ($name !== null && $source !== null) {
        copyContainer($name, $_POST['description'] ?? '', $source, $_POST['autostart'] ?? '', $_POST['mac'] ?? '');
      }
      break;
    case 'showConfig':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->showConfig();
      }
      break;
    case 'saveConfig':
      $updatedConfig = trim((string)($_POST['updatedConfig'] ?? ''));
      $container = sanitizeContainerName($_POST['container'] ?? '');
      if ($container === null) {
        break;
      }
      $containerConfig = rtrim($settings->default_path, '/') . "/" . $container . "/config";
      if (file_exists(dirname($containerConfig))) {
        file_put_contents($containerConfig, $updatedConfig);
        $containerObj = new Container($container);
        if ($containerObj->state == "RUNNING") {
          $containerObj->restartContainer();
        }
      }
      break;
    case 'fromSnapshot':
      $name = sanitizeContainerName($_POST['name'] ?? '');
      $source = sanitizeContainerName($_POST['container'] ?? '');
      $snapshot = basename(trim((string)($_POST['snapshot'] ?? '')));
      if ($name !== null && $source !== null && preg_match('/^[a-zA-Z0-9_.-]+$/', $snapshot)) {
        createFromSnapshot($name, $_POST['description'] ?? '', $source, $snapshot, $_POST['autostart'] ?? '', $_POST['mac'] ?? '');
      }
      break;
    case 'fromBackup':
      $name = sanitizeContainerName($_POST['name'] ?? '');
      $source = sanitizeContainerName($_POST['container'] ?? '');
      $backup = basename(trim((string)($_POST['backup'] ?? '')));
      if ($name !== null && $source !== null && preg_match('/^[a-zA-Z0-9_.-]+$/', $backup)) {
        createFromBackup($name, $_POST['description'] ?? '', $source, $backup, $_POST['autostart'] ?? '', $_POST['mac'] ?? '');
      }
      break;
    case 'setDescription':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->setDescription($_POST['description'] ?? '');
      }
      break;
    case 'delDescription':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->delDescription();
      }
      break;
    case 'setWebUIURL':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->setWebuiurl($_POST['webuiurl'] ?? '');
      }
      break;
    case 'delWebUIURL':
      $cont = sanitizeContainerName($_POST['container'] ?? '');
      if ($cont !== null) {
        $container = new Container($cont);
        $container->delWebuiurl();
      }
      break;
    case 'createTEMPLATE':
      $name = sanitizeContainerName($_POST['name'] ?? '');
      if ($name !== null) {
        createfromTemplate($name, $_POST['description'] ?? '', $_POST['repository'] ?? '', $_POST['webui'] ?? '', $_POST['icon'] ?? '', $_POST['startcont'] ?? '', $_POST['autostart'] ?? '', $_POST['convertbdev'] ?? '', $_POST['mac'] ?? '', $_POST['supportlink'] ?? '', $_POST['donatelink'] ?? '');
      }
      break;
    default:
      break;
  }
}

if (isset($_POST['action']) && $_POST['action'] == 'updateValues') {
    if (!is_dir('/tmp/lxc/containers')) {
        @mkdir('/tmp/lxc/containers', 0755, true);
    }
    file_put_contents('/tmp/lxc/containers/active', time());
    $rawNames = $_POST['containerNames'] ?? '[]';
    $containerNames = is_string($rawNames) ? json_decode($rawNames, true) : (is_array($rawNames) ? $rawNames : []);
    if (!is_array($containerNames)) {
        $containerNames = [];
    }
    $data = [];
    foreach ($containerNames as $name) {
        $cleanName = sanitizeContainerName($name);
        if ($cleanName === null) {
            continue;
        }
        $container = new Container($cleanName);
        $cpu_usage = $container->cpu_usage;
        $memoryUse = $container->memoryUse;
        $ips = $container->ips;
        $totalBytes = $container->totalBytes;
        $data[] = ['name' => $cleanName, 'cpu_usage' => $cpu_usage, 'memoryUse' => $memoryUse, 'ips' => $ips, 'totalBytes' => $totalBytes];
    }
    echo json_encode($data);
}
