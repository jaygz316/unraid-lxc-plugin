<?php

require_once 'functions.php';
class Settings {

  public $default_path;
  public $default_bdevtype;
  public $default_timeout;
  public $default_startdelay;
  public $default_interface;
  public $available_interfaces;
  public $change_net_containers;
  public $status;
  public $dynamic_stats;
  public $default_cont_url;
  public $backup_enabled;
  public $backup_path;
  public $backup_keep;
  public $backup_threads;
  public $backup_compression;
  public $backup_use_snapshot;
  public $github_user;
  public $github_token;

  function __construct() {
    $this->default_path = getVariable('/boot/config/plugins/lxc/lxc.conf', 'lxc.lxcpath');
    $this->default_bdevtype = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'BDEVTYPE');
    $this->default_timeout = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'TIMEOUT');
    $this->default_startdelay = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'AUTOSTART_DELAY');
    $this->default_interface = getVariable('/boot/config/plugins/lxc/default.conf', 'lxc.net.0.link');
    $this->available_interfaces = getAvailableInterfaces();
    $this->status = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'SERVICE');
    $this->dynamic_stats = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'DYNAMIC_STATS');
    $this->default_cont_url = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_CONTAINER_URL');
    $this->backup_enabled = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_SERVICE');
    $this->backup_path = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_PATH');
    $this->backup_keep = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_KEEP');
    $this->backup_threads = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_THREADS');
    $this->backup_compression = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_COMPRESSION');
    $this->backup_use_snapshot = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_USE_SNAPSHOT');
    $this->github_user = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_GITHUB_USER');
    $this->github_token = getVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_GITHUB_TOKEN');
  }

  function changeConfig($started, $default_path, $default_bdevtype, $service, $dynamic_stats, $timeout, $startdelay, $interface, $change_net_containers, $default_cont_url, $backup_enabled, $backup_path, $backup_keep, $backup_threads, $backup_compression, $backup_use_snapshot) {
    $activeContainers = getActiveContainers();
    $availContainers = getAllContainers();

    foreach ($activeContainers as $container) {
      exec('logger "LXC: Stopping container ' . $container . '"');
      exec('lxc-stop --timeout='. (int)$this->default_timeout . ' ' . escapeshellarg($container) . ' 2>/dev/null');
      exec('logger "LXC: Container ' . $container . ' stopped"');
    }

    $default_path = rtrim($default_path, '/');

    setVariable('/boot/config/plugins/lxc/lxc.conf', 'lxc.lxcpath', $default_path);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'BDEVTYPE', $default_bdevtype);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'TIMEOUT', $timeout);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'AUTOSTART_DELAY', $startdelay);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'SERVICE', $service);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'DYNAMIC_STATS', $dynamic_stats);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_CONTAINER_URL', $default_cont_url);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_SERVICE', $backup_enabled);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_PATH', $backup_path);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_KEEP', $backup_keep);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_THREADS', $backup_threads);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_COMPRESSION', $backup_compression);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_USE_SNAPSHOT', $backup_use_snapshot);

    if (file_exists('/boot/config/plugins/lxc/default.conf')) {
      if (preg_match('/^(vhost|eth|bond)\d+$/', $interface)) {
        exec('sed -i "/^lxc\.net\.0\.type/c lxc.net.0.type = macvlan" /boot/config/plugins/lxc/default.conf');
        exec('sed -i "/^lxc\.net\.0\.link/c lxc.net.0.link = ' . escapeshellarg($interface) . '" /boot/config/plugins/lxc/default.conf');
        if (strpos(file_get_contents('/boot/config/plugins/lxc/default.conf'), 'lxc.net.0.macvlan.mode = bridge') === false) {
          exec('sed -i "/^lxc\.net\.0\.type/a lxc.net.0.macvlan.mode = bridge" /boot/config/plugins/lxc/default.conf');
        }
      } else {
        exec('sed -i "/^lxc\.net\.0\.type/c lxc.net.0.type = veth" /boot/config/plugins/lxc/default.conf');
        exec('sed -i "/^lxc\.net\.0\.link/c lxc.net.0.link = ' . escapeshellarg($interface) . '" /boot/config/plugins/lxc/default.conf');
        exec('sed -i "/^lxc\.net\.0\.macvlan\.mode/d" /boot/config/plugins/lxc/default.conf');
      }
    }

    if ($change_net_containers == 'on' ) {
      foreach ($availContainers as $container) {
        $contConfig = $default_path . '/' . $container->name . '/config';
        if (!file_exists($contConfig)) {
          continue;
        }
        if (preg_match('/^(vhost|eth|bond)\d+$/', $interface)) {
          exec('sed -i "/^lxc\.net\.0\.type/c lxc.net.0.type = macvlan" ' . escapeshellarg($contConfig));
          exec('sed -i "/^lxc\.net\.0\.link/c lxc.net.0.link = ' . escapeshellarg($interface) . '" ' . escapeshellarg($contConfig));
          if (strpos(file_get_contents($contConfig), 'lxc.net.0.macvlan.mode = bridge') === false) {
            exec('sed -i "/^lxc\.net\.0\.type/a lxc.net.0.macvlan.mode = bridge" ' . escapeshellarg($contConfig));
          }
        } else {
          exec('sed -i "/^lxc\.net\.0\.type/c lxc.net.0.type = veth" ' . escapeshellarg($contConfig));
          exec('sed -i "/^lxc\.net\.0\.link/c lxc.net.0.link = ' . escapeshellarg($interface) . '" ' . escapeshellarg($contConfig));
          exec('sed -i "/^lxc\.net\.0\.macvlan\.mode/d" ' . escapeshellarg($contConfig));
        }
      }
    }
  
    if (is_link('/var/cache/lxc')) {
      unlink('/var/cache/lxc');
    } elseif (is_dir('/var/cache/lxc')) {
      exec('rm -rf /var/cache/lxc');
    }

    if (!is_dir($default_path . '/cache')) {
      mkdir($default_path . '/cache', 0755, true);
    }

    if (!is_file('/etc/lxc/default.conf')) {
      @symlink("/boot/config/plugins/lxc/default.conf", "/etc/lxc/default.conf");
    }

    if (!is_file('/etc/lxc/lxc.conf')) {
      @symlink("/boot/config/plugins/lxc/lxc.conf", "/etc/lxc/lxc.conf");
    }

    if (!file_exists('/var/cache/lxc') && !is_link('/var/cache/lxc')) {
      @symlink($default_path . "/cache/", "/var/cache/lxc");
    }

    $cfg = @parse_ini_file('/boot/config/plugins/lxc/plugin.cfg');
    $service_status = $cfg['SERVICE'] ?? '';
    if ($started == "enabled" && $service_status == "enabled") {
      exec('lxc-autostart');
    }

    if (file_exists('/usr/local/emhttp/plugins/lxc/system/LXC_usage')) {
      if ($service_status == "enabled") {
        chmod("/usr/local/emhttp/plugins/lxc/system/LXC_usage", 0755);
      } else {
        chmod("/usr/local/emhttp/plugins/lxc/system/LXC_usage", 0644);
      }
    }

    $clean_url = trim(str_replace(['"', "'", '\\', '`', '$', ';', "\n", "\r"], '', (string)$default_cont_url));
    if (file_exists('/usr/share/lxc/templates/lxc-download')) {
      exec("sed -i \"/^DOWNLOAD_SERVER=\\\"*/c\\DOWNLOAD_SERVER=\\\"" . $clean_url . "\\\"\" /usr/share/lxc/templates/lxc-download");
    }
  }

  function changeMisc($timeout, $startdelay, $dynamic_stats, $default_cont_url) {
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'TIMEOUT', $timeout);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'AUTOSTART_DELAY', $startdelay);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'DYNAMIC_STATS', $dynamic_stats);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_CONTAINER_URL', $default_cont_url);

    $clean_url = trim(str_replace(['"', "'", '\\', '`', '$', ';', "\n", "\r"], '', (string)$default_cont_url));
    if (file_exists('/usr/share/lxc/templates/lxc-download')) {
      exec("sed -i \"/^DOWNLOAD_SERVER=\\\"*/c\\DOWNLOAD_SERVER=\\\"" . $clean_url . "\\\"\" /usr/share/lxc/templates/lxc-download");
    }
  }

  function changeBackup($backup_enabled, $backup_path, $backup_keep, $backup_threads, $backup_compression, $backup_use_snapshot) {
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_SERVICE', $backup_enabled);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_PATH', $backup_path);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_KEEP', $backup_keep);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_THREADS', $backup_threads);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_COMPRESSION', $backup_compression);
    setVariable('/boot/config/plugins/lxc/plugin.cfg', 'LXC_BACKUP_USE_SNAPSHOT', $backup_use_snapshot);
    if (!empty($backup_path) && !file_exists($backup_path)) {
      mkdir($backup_path, 0755, true);
    }
  }

}
