<?php

require_once 'Settings.php';
require_once 'Snapshot.php';
class Container {

  public $name;
  public $state;
  public $autostart;
  public $mac;
  public $cpus;
  public $snapshots;
  public $backups;
  public $backup_path;
  public $cpu_usage;
  public $ips;
  public $distribution;
  public $memoryUse;
  public $setmemory;
  public $totalBytes;
  public $pid;
  public $uptime;
  public $container_order;
  public $settings;
  public $config;
  public $path;
  public $description;
  public $lxcwebui;
  public $supportlink;
  public $donatelink;

  function __construct($name) {
    $this->settings = new Settings();
    $this->name = $name;
    $this->path = $this->settings->default_path . '/' . $this->name;
    $this->config = $this->path . '/config';
    $this->state = getContainerStats($this->name, "State");
    $this->autostart = getVariable($this->config, 'lxc.start.auto');
    $this->mac = getVariable($this->config, 'lxc.net.0.hwaddr');
    $this->snapshots = $this->getSnapshots();
    $this->backups = $this->getBackups();
    $this->backup_path = realpath($this->settings->backup_path);
    if ($this->state === "RUNNING") {
      if (file_exists('/tmp/lxc/containers/' . $this->name)) {
        $container_stats = parse_ini_file('/tmp/lxc/containers/' . $this->name);
      } else {
        $container_stats = array("CPU" => "na", "MEMORY" => "", "IPS" => "");
      }
      $this->cpu_usage = $container_stats['CPU'];
      $this->memoryUse = $container_stats['MEMORY'];
      $this->ips = $container_stats['IPS'];
    } else {
      $this->cpu_usage = "";
      $this->memoryUse = "";
      $this->ips = "";
    }
    $this->distribution = trim(exec("grep -oP '(?<=dist )\w+' " . escapeshellarg($this->config) . " 2>/dev/null | head -1 | sed 's/\"//g'"));
    $this->setmemory = $this->getMemoryLimit();
    $this->totalBytes = getContainerStats($this->name, "Total bytes");
    $this->pid = getContainerStats($this->name, "PID");
    $this->uptime = $this->getUptime();
    $order = getVariable($this->config, '#container_order');
    if (empty($order)) {
      $this->container_order = null;
    } else {
      $this->container_order = $order;
    }
    $this->cpus = $this->getCpus();
    $this->description = getVariable($this->config, '#container_description');
    $webui = getVariable($this->config, '#container_webui');
    if (empty($webui)) {
      $this->lxcwebui = null;
    } else {
      $this->lxcwebui = $webui;
    }
    $this->supportlink = getVariable($this->config, '#container_supportlink');
    $this->donatelink = getVariable($this->config, '#container_donatelink');
  }

  private function getSnapshots() {
    $snapshots = array();
    exec("lxc-snapshot -L " . escapeshellarg($this->name), $snapshotList);
    if (!empty($snapshotList) && $snapshotList[0] != "No snapshots") {
      foreach ($snapshotList as $snapshot){
        $sorted = explode(" ", $snapshot);
        $snapshots[] = new Snapshot($sorted[0], str_replace(":", "_", $sorted[2]), $sorted[3]);
      }
    }
    return $snapshots;
  }

  private function getBackups() {
    $backups = array();
    $backup_dir = realpath($this->settings->backup_path);
    if (!empty($backup_dir) && is_dir($backup_dir . "/" . $this->name)) {
      exec("ls -1 " . escapeshellarg($backup_dir . "/" . $this->name) . " 2>/dev/null | grep -E \"^" . preg_quote($this->name, '/') . "_[0-9]{2}\.[0-9]{2}\.[0-9]{2}_.+\.tar\.xz$\" 2>/dev/null", $backupList);
    }
    if (isset($backupList)) {
      foreach ($backupList as $backup){
        $pattern = '/^(.*?)_(\d+\.\d+\.\d+)_(\d{4}-\d{2}-\d{2})(\.tar\.xz)$/';
        if (preg_match($pattern, $backup, $sorted)) {
          $backups[] = array('name' => $sorted[1], 'date' => $sorted[3], 'time' => $sorted[2], 'backupObject' => null);
        }
      }
      usort($backups, function($a, $b) {
        if ($a['date'] == $b['date']) {
          return strcmp($b['time'], $a['time']);
        }
        return strcmp($b['date'], $a['date']);
      });
      foreach ($backups as &$backup) {
        $backup['backupObject'] = new Backup($backup['name'], $backup['date'], $backup['time']);
      }
      $backups = array_column($backups, 'backupObject');
    }
    return $backups;
  }

  private function getMemoryLimit() {
    $rawmem = '';
    if (file_exists($this->config)) {
      $rawmem = trim(shell_exec("grep 'lxc.cgroup2.memory.max' " . escapeshellarg($this->config) . " 2>/dev/null | grep -v '^#' | awk -F= '{ print $2 }'") ?? '');
      if (empty($rawmem)) {
        $rawmem = trim(shell_exec("grep 'lxc.cgroup.memory.limit_in_bytes' " . escapeshellarg($this->config) . " 2>/dev/null | grep -v '^#' | awk -F= '{ print $2 }'") ?? '');
      }
    }

    $rawmem = trim($rawmem);
    $setmembytes = '';

    if (!empty($rawmem) && strtolower($rawmem) !== 'max') {
      if (is_numeric($rawmem)) {
        $setmembytes = (float)$rawmem;
      } elseif (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([a-zA-Z]+)$/', $rawmem, $matches)) {
        $val = (float)$matches[1];
        $unit = strtoupper($matches[2]);
        if ($unit === 'G' || $unit === 'GB' || $unit === 'GIB') {
          $setmembytes = $val * 1024 * 1024 * 1024;
        } elseif ($unit === 'M' || $unit === 'MB' || $unit === 'MIB') {
          $setmembytes = $val * 1024 * 1024;
        } elseif ($unit === 'K' || $unit === 'KB' || $unit === 'KIB') {
          $setmembytes = $val * 1024;
        } elseif ($unit === 'T' || $unit === 'TB' || $unit === 'TIB') {
          $setmembytes = $val * 1024 * 1024 * 1024 * 1024;
        } elseif ($unit === 'B' || $unit === 'BYTES') {
          $setmembytes = $val;
        }
      }
    }

    if (empty($setmembytes)) {
      $memtotal = shell_exec('awk \'/MemTotal/ { printf "%.0f\n", $2 * 1024 }\' /proc/meminfo 2>/dev/null');
      $setmembytes = trim($memtotal ?? '');
    }

    if (!is_numeric($setmembytes) || (float)$setmembytes <= 0) {
      return '0Bytes';
    }

    $setmembytes = (float)$setmembytes;
    if ($setmembytes >= 1024 * 1024 * 1024 * 1024) {
      return round($setmembytes / (1024 * 1024 * 1024 * 1024), 2) . 'TiB';
    } elseif ($setmembytes >= 1024 * 1024 * 1024) {
      return round($setmembytes / (1024 * 1024 * 1024), 2) . 'GiB';
    } elseif ($setmembytes >= 1024 * 1024) {
      return round($setmembytes / (1024 * 1024), 2) . 'MiB';
    } elseif ($setmembytes >= 1024) {
      return round($setmembytes / 1024, 2) . 'KiB';
    } else {
      return $setmembytes . 'Bytes';
    }
  }

  private function getUptime() {
    if ($this->state !== "RUNNING" || !is_numeric($this->pid) || (int)$this->pid <= 0) {
      return ($this->state === "RUNNING") ? "0" : "Stopped";
    }

    $starttime = shell_exec("ps -p " . escapeshellarg($this->pid) . " -o lstart --no-headers 2>/dev/null");
    if ($starttime !== null && trim($starttime) !== "") {
      $parsed_starttime = strtotime(trim($starttime));
      if ($parsed_starttime !== false && $parsed_starttime > 0) {
        $timenow = time();
        $seconds_elapsed = $timenow - $parsed_starttime;
        if ($seconds_elapsed < 0) {
          $seconds_elapsed = 0;
        }
        $days = floor($seconds_elapsed / 86400);
        $hours = floor(($seconds_elapsed % 86400) / 3600);
        $minutes = floor(($seconds_elapsed % 3600) / 60);
        $seconds = $seconds_elapsed % 60;

        if ($days > 0) {
          return "$days day" . ($days > 1 ? "s" : "");
        } elseif ($hours > 0) {
          return "$hours hour" . ($hours > 1 ? "s" : "");
        } elseif ($minutes > 0) {
          return "$minutes minute" . ($minutes > 1 ? "s" : "");
        } else {
          return "$seconds second" . ($seconds > 1 ? "s" : "");
        }
      }
    }

    return ($this->state === "RUNNING") ? "0" : "Stopped";
  }

  private function getCpus() {
    $cpus = getVariable($this->config, 'lxc.cgroup2.cpuset.cpus');
    if (empty($cpus)) {
      $cpus = getVariable($this->config, 'lxc.cgroup.cpuset.cpus');
    }
    if (!empty($cpus)) {
      return $cpus;
    }
    if (is_numeric($this->pid) && (int)$this->pid > 0 && file_exists("/proc/" . $this->pid . "/status")) {
      return trim(exec("grep 'Cpus_allowed_list' /proc/" . escapeshellarg($this->pid) . "/status 2>/dev/null | awk '{print $2}'"));
    }
    return '';
  }

  function startContainer() {
    exec('logger "LXC: Starting container ' . $this->name . '"');
    exec('lxc-start ' . escapeshellarg($this->name) . ' 2>&1', $output, $retval);
    if ($retval == 1) {
      exec('logger "LXC: error: Container ' . $this->name . ' failed to start"');
      foreach ($output as $error) {
        exec('logger "LXC: ' . $error . '"');
      }
    } else {
      exec('logger "LXC: Container ' . $this->name . ' started"');
      // add sleep to wait for IP address
      sleep(3);
    }
  }

  function stopContainer() {
    exec('logger "LXC: Stopping container ' . $this->name . '"');
    exec('lxc-stop --timeout=' . (int)$this->settings->default_timeout . ' ' . escapeshellarg($this->name));
    exec('logger "LXC: Container ' . $this->name . ' stopped"');
  }
  function restartContainer() {
    $this->stopContainer();
    sleep(1);
    $this->startContainer();
  }

  function freezeContainer() {
    exec('logger "LXC: Freezing container ' . $this->name . '"');
    exec('lxc-freeze ' . escapeshellarg($this->name));
    exec('logger "LXC: Container ' . $this->name . ' frozen"');
  }

  function unfreezeContainer() {
    exec('logger "LXC: Unfreezing container ' . $this->name . '"');
    exec('lxc-unfreeze ' . escapeshellarg($this->name));
    exec('logger "LXC: Container ' . $this->name . ' unfrozen"');
  }

  function killContainer() {
    if ($this->state != "STOPPED") {
      exec('logger "LXC: Killing container ' . $this->name . '"');
    }
    exec('lxc-stop --kill ' . escapeshellarg($this->name));
    if ($this->state != "STOPPED") {
      exec('logger "LXC: Container ' . $this->name . ' killed"');
    }
  }

  function destroyContainer() {
    if ($this->state != "STOPPED") {
      exec('logger "LXC: Destroying container ' . $this->name . '"');
    }

    foreach ($this->snapshots as $snapshot) {
      $this->deleteSnapshot($snapshot->name);
    }

    $this->killContainer();
    exec('umount ' . escapeshellarg($this->path . '/rootfs') . ' 2>/dev/null');
    if (is_dir($this->path . '/rootfs/var/empty')) {
      exec('find ' . escapeshellarg($this->path . '/rootfs/var/empty') . ' -exec chattr -i {} \; 2>/dev/null');
    }
    exec('lxc-destroy -s ' . escapeshellarg($this->name) . ' 2>&1', $output, $retval);
    if ($retval == 1) {
      echo '<p style="color:red;">';
      echo "ERROR, failed to destroy " . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . "!<br/><br/>";
      exec('logger "LXC: error: Failed to destroy Container ' . $this->name . '"');
      foreach ($output as $error) {
        exec('logger "LXC: ' . $error . '"');
        echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "<br/>";
      }
    } else {
    if (file_exists($this->settings->default_path . '/custom-icons/' . $this->name . '.png')) {
        exec('rm ' . escapeshellarg($this->settings->default_path . '/custom-icons/' . $this->name . '.png'));
    }
      $settings = new Settings();
      if($settings->default_bdevtype == "zfs") {
        $dataset = (explode('/', $this->path)[2] ?? '') . '/zfs_lxccontainers/' . $this->name;
        $check_dataset = shell_exec('zfs list -H -o name ' . escapeshellarg($dataset) . ' 2>/dev/null');
        if (trim($check_dataset ?? '') == trim($dataset)) {
          exec('zfs destroy -r ' . escapeshellarg($dataset));
        }
      }
      echo '<p>Container ' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . ' destroyed!</p>';
      exec('logger "LXC: Container ' . $this->name . ' destroyed"');
    }
  }

  function setAutostart($autostart) {
    setVariable($this->config, 'lxc.start.auto', $autostart);
  }

  function deleteSnapshot($snapshot) {
    exec('logger "LXC: Deleting snapshot ' . $snapshot . ' from container ' . $this->name . '"' );
    exec('umount ' . escapeshellarg($this->path . '/snaps/' . $snapshot . '/rootfs') . ' 2>/dev/null');
    exec('lxc-snapshot -d ' . escapeshellarg($snapshot) . ' ' . escapeshellarg($this->name) . ' 2>&1', $output, $retval);
    if ($retval == 1) {
      exec('logger "LXC: error: Failed to remove Snapshot ' . $snapshot . ' from Container ' . $this->name . '"');
      foreach ($output as $error) {
        exec('logger "LXC: ' . $error . '"');
      }
    } else {
      exec('logger "LXC: Snapshot ' . $snapshot . ' from container ' . $this->name. ' deleted"');
    }
  }

  function createSnapshot() {
    $settings = new Settings();
    $this->stopContainer();
    exec('lxc-snapshot ' . escapeshellarg($this->name) . ' 2>&1', $output, $retval);
    if ($retval == 1) {
      echo '<p style="color:red;">';
      echo "ERROR, failed to create snapshot from " . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . "!<br/><br/>";
      exec('logger "LXC: error: Failed to create snapshot from Container ' . $this->name . '"');
      foreach ($output as $error) {
        exec('logger "LXC: ' . $error . '"');
        echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "<br/>";
      }
    } else {
      if($settings->default_bdevtype == "zfs") {
        $datasets = shell_exec("zfs list -r -H -o name " . escapeshellarg((explode('/', $settings->default_path)[2] ?? '') . "/zfs_lxccontainers/" . $this->name) . " 2>/dev/null");
        foreach (explode("\n", trim($datasets ?? '')) as $dataset) {
          $dataset_name = str_replace((explode('/', $settings->default_path)[2] ?? '') . "/zfs_lxccontainers/" . $this->name . "/", '', $dataset);
          if (preg_match('/snap\d+/', $dataset_name)) {
            shell_exec("zfs set canmount=on " . escapeshellarg((explode('/', $settings->default_path)[2] ?? '') . "/zfs_lxccontainers/" . $this->name . "/" . $dataset_name));
            shell_exec("zfs mount " . escapeshellarg((explode('/', $settings->default_path)[2] ?? '') . "/zfs_lxccontainers/" . $this->name . "/" . $dataset_name));
          }
        }
      }
      echo '<p>Snapshot from container ' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . ' created!</p>';
      exec('logger "LXC: Snapshot from container ' . $this->name. ' created"');
      if ($this->state == "RUNNING") {
        $this->startContainer();
      }
    }
  }

  function createBackup() {
    exec('lxc-autobackup ' . escapeshellarg($this->name) . ' 2>&1', $output, $retval);
    if ($retval == 1) {
      echo '<p style="color:red;">';
      echo "ERROR, failed to create backup from " . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . "!<br/><br/>";
      exec('logger "LXC: error: Failed to create backup from Container ' . $this->name . '"');
      foreach ($output as $error) {
        exec('logger "LXC: ' . $error . '"');
        echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "<br/>";
      }
    } else {
      echo '<p>Backup from container ' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . ' created!</p>';
      exec('logger "LXC: Backup from container ' . $this->name. ' created"');
    }
  }

  function deleteBackup($backup) {
    exec('logger "LXC: Deleting backup ' . $backup . ' from container ' . $this->name . '"' );
    $backup_file = $this->backup_path . "/" . $this->name . "/" . $backup . ".tar.xz";
    if (file_exists($backup_file)) {
      unlink($backup_file);
    }
    exec('logger "LXC: Backup ' . $backup . ' from container ' . $this->name. ' deleted"');
    $files = glob($this->backup_path . "/" . $this->name . '/*');
    if (empty($files) && is_dir($this->backup_path . "/" . $this->name)) {
      rmdir($this->backup_path . "/" . $this->name);
      exec('logger "LXC: Backup directory for container ' . $this->name . ' empty, deleting directory"');
    }
  }

  function setMac($mac) {
    setVariable($this->config, 'lxc.net.0.hwaddr', $mac);
  }

  function setDescription($desc){
    setVariable($this->config, '#container_description', $desc);
  }

  function delDescription(){
    setVariable($this->config, '#container_description', '');
  }

  function setWebuiurl($webuiurl){
    setVariable($this->config, '#container_webui', $webuiurl);
  }

  function delWebuiurl(){
    setVariable($this->config, '#container_webui', '');
  }

  function setSupportlink($supporturl){
    setVariable($this->config, '#container_supportlink', $supporturl);
  }
  
  function setDonatelink($donateurl){
    setVariable($this->config, '#container_donatelink', $donateurl);
  }

  function addConfig($configadditions){
    file_put_contents($this->config, "\n\n#ADDITIONAL ENTRIES FROM UNRAID CA APP TEMPLATE\n" . preg_replace('/<br\s*\/?>/', "\n", $configadditions), FILE_APPEND);
  }

  function showConfig() {
    if (!file_exists($this->config)) {
      return;
    }
    while (@ ob_end_flush());
    $proc = popen("cat " . escapeshellarg($this->config), "r");
    if ($proc) {
      while (!feof($proc)) {
        echo (fread($proc, 4096) . "\n");
        @ flush();
      }
      pclose($proc);
    }
  }
}
