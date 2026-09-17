<?php

require_once 'Container.php';
require_once 'Settings.php';

function getNewMacAddress() {
  return "52:54:00:" .strtoupper(implode(':', str_split(substr(md5(mt_rand()), 0, 6), 2)));
}

function getVariable($path, $option) {
  if (empty($path) || !file_exists($path)) {
    return null;
  }
  $contents = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($contents === false) {
    return null;
  }
  foreach ($contents as $line) {
    $line = trim(preg_replace('/\s*=\s*/', '=', $line));
    if (strpos($line, $option . '=') === 0) {
      $parts = explode('=', $line, 2);
      return isset($parts[1]) ? trim(htmlspecialchars(trim($parts[1]), ENT_QUOTES, 'UTF-8')) : '';
    }
  }
  return null;
}

function getContainerStats($container, $option) {
  if (empty($container)) {
    return '';
  }
  exec("lxc-info " . escapeshellarg($container) . " 2>/dev/null", $content);
  foreach ($content as $index => $string) {
    if (strpos($string, $option) !== FALSE) {
      $parts = explode(':', $string, 2);
      return isset($parts[1]) ? trim($parts[1]) : '';
    }
  }
  return '';
}

function getAvailableInterfaces() {
  $interfaces = array();
  exec("ip -o link show 2>/dev/null | awk -F': ' '{print $2}'", $output);
  foreach ($output as $line) {
    $interfaceName = trim($line);
    $interfaceName = explode('@', $interfaceName)[0];
    if (preg_match('/^(virbr|vhost|bond|eth|br)\d\S*/', $interfaceName)) {
      $interfaces[] = $interfaceName;
    }
  }
  $interfaces = array_values(array_unique($interfaces));
  $sortOrder = ['br', 'eth', 'bond', 'vhost', 'virbr'];
  usort($interfaces, function ($a, $b) use ($sortOrder) {
    preg_match('/^([a-zA-Z]+)(\d+)?/', $a, $matchA);
    preg_match('/^([a-zA-Z]+)(\d+)?/', $b, $matchB);
    $prefixA = $matchA[1] ?? $a;
    $prefixB = $matchB[1] ?? $b;
    $numA = isset($matchA[2]) ? (int)$matchA[2] : 0;
    $numB = isset($matchB[2]) ? (int)$matchB[2] : 0;

    $posA = array_search($prefixA, $sortOrder);
    $posB = array_search($prefixB, $sortOrder);
    $posA = ($posA === false) ? 999 : $posA;
    $posB = ($posB === false) ? 999 : $posB;

    if ($posA === $posB) {
      if ($numA === $numB) {
        return strcmp($a, $b);
      }
      return $numA - $numB;
    }
    return $posA - $posB;
  });
  return $interfaces;
}

function getInterfaces() {
  return getAvailableInterfaces();
}

function getActiveContainers() {
  $containers = array();
  exec("lxc-ls --active 2>/dev/null", $output);
  foreach ($output as $line) {
    $containers[] = $line;
  }

  return $containers;
}

function getAllContainers() {
  $containers = shell_exec("lxc-ls 2>/dev/null");
  if (!empty($containers)) {
    $containers = preg_replace('!\s+!', ' ', $containers);
    $containers = explode(" ",trim($containers));

    $allContainers = array();
    foreach ($containers as $container) {
      $allContainers[] = new Container($container);
    }
  } else {
    $allContainers = array();
  }

  return $allContainers;
}

function setVariable($file, $variable, $value) {
  if (empty($file) || !file_exists($file)) {
    return;
  }
  $contents = @file_get_contents($file);
  if ($contents === false) {
    return;
  }

  $newFile = [];
  $lines = explode("\n", $contents);
  $found = false;

  foreach ($lines as $line) {
    if (strpos($line, $variable . '=') === 0 || strpos($line, $variable . ' =') === 0 || $line === $variable) {
      $newFile[] = $variable . "=" . $value;
      $found = true;
    } else {
      $newFile[] = $line;
    }
  }
  if (!$found) {
    $newFile[] = $variable . "=" . $value;
  }

  file_put_contents($file, implode(PHP_EOL, $newFile));
}

function getCpus(){
  exec('cat /sys/devices/system/cpu/*/topology/thread_siblings_list 2>/dev/null | sort -nu', $cpus);
  $allCpus = array();
  $vCpus = array();
  foreach ($cpus as $cpu) {
    $cpu = explode(',', trim($cpu));
    if (!empty($cpu[0]) || $cpu[0] === '0') {
      $allCpus[] = $cpu[0];
      $vCpus[] = $cpu[1] ?? $cpu[0];
    }
  }

  sort($allCpus, SORT_NUMERIC);
  sort($vCpus, SORT_NUMERIC);

  return array('allcpus' => $allCpus, 'vcpus' => $vCpus);
}

function createContainer($name, $description, $distribution, $release, $startcont, $autostart, $mac) {
  $settings = new Settings();
  $default_path = rtrim($settings->default_path, '/');
  $pool = explode('/', $default_path)[2] ?? '';

  if($settings->default_bdevtype == "zfs") {
    $bdev = "--bdev=zfs --zfsroot=" . escapeshellarg($pool . "/zfs_lxccontainers/" . $name);
  } elseif($settings->default_bdevtype == "btrfs") {
    $bdev = "--bdev=btrfs";
  } else {
    $bdev = "--bdev=dir";
  }
  exec('logger "LXC: Creating container ' . $name . '"');
  while (@ ob_end_flush());
  exec("lxc-create --name " . escapeshellarg($name) . " " . $bdev . " --template download -- --dist " . escapeshellarg($distribution) . " --release " . escapeshellarg($release) . " --arch amd64 2>&1", $output, $retval);
  if ($retval == 1) {
    echo '<p style="color:red;">';
    echo "ERROR, failed to create container " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "!<br/><br/>";
    exec('logger "LXC: error: Failed to create Container ' . $name . '"');
    foreach ($output as $error) {
      exec('logger "LXC: ' . $error . '"');
      echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "<br/>";
    }
    echo '</p>';
  } else {
    if($settings->default_bdevtype == "zfs") {
      exec("zfs set canmount=on " . escapeshellarg($pool . "/zfs_lxccontainers/" . $name . "/" . $name));
      exec("zfs mount " . escapeshellarg($pool . "/zfs_lxccontainers/" . $name . "/" . $name));
    }
    exec('logger "LXC: Container ' . $name . ' created"');
    $container = new Container($name);
    $container->setMac($mac);
    $container->setAutostart(($autostart == "true" || $autostart == "1") ? 1 : 0);
    echo '<p style="color:green;">';
    foreach ($output as $message) {
      echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "<br/>";
    }
    echo '</p>';
    if ($startcont == "true" || $startcont == "1") {
      $container->startContainer();
    }
  }
  if (!empty($description) && isset($container)) {
    $container->setDescription($description);
  }
}

function copyContainer($name, $description, $container, $autostart, $mac) {
  $oldContainer = new Container($container);
  $running = $oldContainer->state;
  $oldContainer->stopContainer();
  exec('logger "LXC: Copying container ' . $container . '"');
  exec('lxc-copy -n ' . escapeshellarg($container) . ' -N ' . escapeshellarg($name));
  exec('logger "LXC: Container ' . $container . ' copied to ' . $name . '"');
  $newContainer = new Container($name);
  $newContainer->setMac($mac);
  $newContainer->setAutostart(($autostart == "true" || $autostart == "1") ? 1 : 0);

  if (!empty($description)) {
    $newContainer->setDescription($description);
  }

  if ($running == "RUNNING") {
    $oldContainer->startContainer();
  }
}

function createFromSnapshot($name, $description, $container, $snapshot, $autostart, $mac) {
  exec('logger "LXC: Creating ' . $name . ' from container ' . $container . '-' . $snapshot . '"');
  exec('lxc-snapshot -n ' . escapeshellarg($container) . ' -r ' . escapeshellarg($snapshot) . ' -N ' . escapeshellarg($name));
  exec('logger "LXC: Container ' . $name . ' created from ' . $container . '-' . $snapshot . '"');
  $newContainer = new Container($name);
  $newContainer->setMac($mac);
  $newContainer->setAutostart(($autostart == "true" || $autostart == "1") ? 1 : 0);

  if (!empty($description)) {
    $newContainer->setDescription($description);
  }
}

function createFromBackup($name, $description, $container, $backup, $autostart, $mac) {
  exec('lxc-autobackup --restore --gui-restore=' . escapeshellarg($container . '_' . $backup) . ' --name=' . escapeshellarg($container) . ' --newname=' . escapeshellarg($name));
  $newContainer = new Container($name);
  $newContainer->setMac($mac);
  $newContainer->setAutostart(($autostart == "true" || $autostart == "1") ? 1 : 0);

  if (!empty($description)) {
    $newContainer->setDescription($description);
  }
}

function downloadLXCproducts($url) {
  $filename = "lxcimages";
  $path = '/tmp/lxc';
  $clean_url = trim($url);
  if (!filter_var($clean_url, FILTER_VALIDATE_URL)) {
    return;
  }
  if (!is_dir($path)) {
    mkdir($path, 0755, true);
  }
  $target = $path . '/' . $filename . '.json';
  if (!file_exists($target) || (time() - filemtime($target)) > 3600) {
    if (file_exists($target)) {
      unlink($target);
    }
    shell_exec("wget -q -O " . escapeshellarg($target) . " " . escapeshellarg($clean_url));
  }
}

function createfromTemplate($name, $description, $repository, $webui, $icon, $startcont, $autostart, $convertbdev, $mac, $supportlink, $donatelink) {
  $settings = new Settings();
  $default_path = rtrim($settings->default_path, '/');
  $repositoryurl = parse_url($repository);
  $repositorypath = isset($repositoryurl['path']) ? explode('/', trim($repositoryurl['path'], '/')) : [];

  if (count($repositorypath) < 2) {
    echo '<p style="color:red;">ERROR, invalid repository URL!</p>';
    return;
  }

  $repoOwner = preg_replace('/[^a-zA-Z0-9_.-]/', '', $repositorypath[0]);
  $repoName = preg_replace('/[^a-zA-Z0-9_.-]/', '', $repositorypath[1]);

  exec('logger "LXC: Creating container ' . $name . ' from repository: ' . $repository . '"');

  if (is_dir($default_path . "/" . $name)) {
    echo '<p style="color:red;">ERROR, failed to create container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br/>Already exists!</p>';
    exec('logger "LXC: error: Failed to create Container ' . $name . ', container already exists"');
    return;
  } else {
    mkdir($default_path . "/" . $name, 0755, true);
  }

  if (!is_dir($default_path . "/cache/template_cache")) {
    mkdir($default_path . "/cache/template_cache", 0755, true);
  }

  if (!empty($settings->github_user) && !empty($settings->github_token)) {
    $githubauth = "-u " . escapeshellarg($settings->github_user . ":" . $settings->github_token) . " ";
  } else {
    $githubauth = "";
  }

  $githubjson = shell_exec("curl " . $githubauth . "-s " . escapeshellarg("https://api.github.com/repos/" . $repoOwner . "/" . $repoName . "/releases/latest"));
  $githubjson = json_decode($githubjson, true);

  if (isset($githubjson['assets']) && is_array($githubjson['assets'])) {
    $assets = $githubjson['assets'];
    $download_assets = [];
    foreach ($assets as $asset) {
      if (strpos($asset['name'], '.tar.xz') !== false && strpos($asset['name'], '.md5') === false) {
        $download_assets[] = ['filename' => $asset['name'], 'url' => $asset['browser_download_url']];
      }
    }
  } else {
    echo '<p style="color:red;">ERROR, failed to create container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br/>Found no assets on GitHub, please try again later!</p>';
    exec('logger "LXC: error: Failed to create Container ' . $name . ', found no assets on GitHub, please try again later"');
    rmdir($default_path . "/" . $name);
    return;
  }

  if (empty($download_assets)) {
    echo '<p style="color:red;">ERROR, failed to create container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br/>Found no archive assets on GitHub!</p>';
    rmdir($default_path . "/" . $name);
    return;
  }

  exec('logger "LXC: Downloading container archive for ' . $name . '"');

  $archivePath = $default_path . '/cache/template_cache/' . basename($download_assets[0]['filename']);
  exec('wget -q -O ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($download_assets[0]['url']), $output, $retval);
  if ($retval == 1) {
    echo '<p style="color:red;">ERROR, failed to create container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br/>Download failed!</p>';
    exec('logger "LXC: error: Failed to create Container ' . $name . ', download failed"');
    rmdir($default_path . "/" . $name);
    if (file_exists($archivePath)) {
      unlink($archivePath);
    }
    return;
  } else {
    $md5Path = $archivePath . '.md5';
    exec('wget -q -O ' . escapeshellarg($md5Path) . ' ' . escapeshellarg($download_assets[0]['url'] . '.md5'), $output, $retval);
    if ($retval == 1) {
      echo '<p style="color:red;">ERROR, failed to create container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br/>Download from md5 failed!</p>';
      exec('logger "LXC: error: Failed to create Container ' . $name . ', download from md5 failed"');
      rmdir($default_path . "/" . $name);
      if (file_exists($archivePath)) {
        unlink($archivePath);
      }
      if (file_exists($md5Path)) {
        unlink($md5Path);
      }
      return;
    }
  }

  exec('logger "LXC: Download from container archive for ' . $name . ' successful"');

  $md5file = exec('md5sum ' . escapeshellarg($archivePath) . ' | awk \'{print $1}\'');
  $md5check = exec('cat ' . escapeshellarg($md5Path) . ' | awk \'{print $1}\'');

  if ($md5file != $md5check) {
    echo '<p style="color:red;">ERROR, failed to create container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br/>MD5 Checksum Error!</p>';
    exec('logger "LXC: error: Failed to create Container ' . $name . ', checksum error"');
    rmdir($default_path . "/" . $name);
    if (file_exists($archivePath)) {
      unlink($archivePath);
    }
    if (file_exists($md5Path)) {
      unlink($md5Path);
    }
    return;
  }

  echo '<p style="color:green;">Unpacking the archive<br/><br/>---</p>';
  exec('logger "LXC: Unpacking archive for container ' . $name . '"');

  exec('tar -C ' . escapeshellarg($default_path . '/' . $name) . ' -xf ' . escapeshellarg($archivePath) . ' 2>/dev/null', $output, $retval);
  if ($retval == 1) {
    echo '<p style="color:red;">ERROR, failed to create container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br/>Extraction failed!</p>';
    exec('logger "LXC: error: Failed to create Container ' . $name . ', extraction failed"');
    rmdir($default_path . "/" . $name);
    if (file_exists($archivePath)) {
      unlink($archivePath);
    }
    if (file_exists($md5Path)) {
      unlink($md5Path);
    }
    return;
  }

  $default_conf = file_exists('/boot/config/plugins/lxc/default.conf') ? file_get_contents('/boot/config/plugins/lxc/default.conf') : '';
  $lxc_config = "# Container specific configuration\n" .
                "lxc.rootfs.path = dir:" . $default_path . "/" . $name . "/rootfs\n" .
                "lxc.uts.name = " . $name . "\n\n" .
                "# Network configuration\n" .
                $default_conf . "\n" .
                "lxc.net.0.hwaddr=00:00:00:00:00:00\n" .
                "lxc.start.auto=0\n";

  file_put_contents($default_path . "/" . $name . "/config", $lxc_config, FILE_APPEND | LOCK_EX);

  $container = new Container($name);
  $container->setMac($mac);
  $container->setAutostart(($autostart == "true" || $autostart == "1") ? 1 : 0);

  if (!empty($description)) {
    $container->setDescription($description);
  }

  if (!empty($webui)) {
    $container->setWebuiurl($webui);
  }

  if (!empty($supportlink)) {
    $container->setSupportlink($supportlink);
  }

  if (!empty($donatelink)) {
    $container->setDonatelink($donatelink);
  }

  if (!empty($icon)) {
    if (!is_dir($default_path . '/custom-icons')) {
      mkdir($default_path . '/custom-icons', 0755, true);
    }
    exec('wget -q -O ' . escapeshellarg($default_path . '/custom-icons/' . $name . '.png') . ' ' . escapeshellarg($icon), $output, $retval);
    if ($retval == 1) {
      exec('logger "LXC: error: download from icon for container ' . $name . ' failed"');
      if (file_exists($default_path . '/custom-icons/' . $name . '.png')) {
        unlink($default_path . '/custom-icons/' . $name . '.png');
      }
    }
  }

  exec("sed -i '/lxc\.mount\.entry.*/d' " . escapeshellarg($default_path . "/" . $name . "/config")); 

  if (file_exists($archivePath)) {
    unlink($archivePath);
  }
  if (file_exists($md5Path)) {
    unlink($md5Path);
  }

  if ($convertbdev == "true" || $convertbdev == "1") {
    $bdevtype = "";
    if (preg_match('/\b(zfs)\b/', $settings->default_bdevtype)) {
      exec('lxc-dirtozfs -q ' . escapeshellarg($name), $output, $retval);
      $bdevtype = "ZFS";
    } elseif (preg_match('/\b(btrfs)\b/', $settings->default_bdevtype)) {
      exec('lxc-dirtobtrfs -q ' . escapeshellarg($name), $output, $retval);
      $bdevtype = "BTRFS";
    }

    echo '<p style="color:green;">Converting container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' to ' . $bdevtype . '</p>';
    exec('logger "LXC: Converting Container ' . $name . ' to ' . $bdevtype . '"');

    if ($retval == 1) {
      echo '<p style="color:red;">ERROR, Conversion from container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br/> to ' . $bdevtype . ' failed!<br/><br/>---</p>';
      exec('logger "LXC: error: Converstion from Container ' . $name . ' to ' . $bdevtype . ' failed"');
      rmdir($default_path . "/" . $name);
      if (file_exists($archivePath)) {
        unlink($archivePath);
      }
    } else {
      echo '<p style="color:green;">Conversion to ' . $bdevtype . ' from container ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' done<br/><br/>---</p>';
      exec('logger "LXC: Conversion to ' . $bdevtype . ' from Container ' . $name . ' done"');
    }
  }

  echo '<p style="color:green;">You just created a container from the repository: ' . htmlspecialchars($repository, ENT_QUOTES, 'UTF-8') . '<br/>Please check out the  <a href="' . htmlspecialchars($repository, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">README</a> from the repository for further infromation!</p>';
  exec('logger "LXC: Container ' . $name . ' created"');

  if ($startcont == "true" || $startcont == "1") {
    $container->startContainer();
  }
}
