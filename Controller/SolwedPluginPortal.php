<?php

namespace FacturaScripts\Plugins\SolwedPlugins\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\SolwedPlugins\Lib\SolwedGitHubPlugins;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use Symfony\Component\HttpFoundation\Response;
use FacturaScripts\Core\Kernel;

class SolwedPluginPortal extends Controller
{
    /** @var array */
    public $pluginList = [];

    /** @var SolwedGitHubPlugins */
    private $github;

    public function __construct(string $className, string $url = '')
    {
        parent::__construct($className, $url);
        $this->github = new SolwedGitHubPlugins();
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Plugin Portal';
        $data['icon'] = 'fas fa-cloud-download-alt';
        $data['showonmenu'] = true;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $action = $this->request->get('action', '');
        switch ($action) {
            case 'install':
                $this->installPluginAction();
                break;

            case 'update':
                $this->updatePluginAction();
                break;
        }

        // Load plugins from GitHub
        $this->pluginList = $this->github->getPlugins();

        // Mark which plugins are already installed
        $this->markInstalledPlugins();
    }

    private function installPluginAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $pluginName = $this->request->get('plugin', '');
        $this->installPlugin($pluginName);
    }

    private function updatePluginAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $pluginName = $this->request->get('plugin', '');
        $this->installPlugin($pluginName, true);
    }

    private function installPlugin(string $pluginName, bool $isUpdate = false): void
    {
        $pluginInfo = $this->github->getPluginInfo($pluginName);
        if (!$pluginInfo) {
            Tools::log()->error('plugin-not-found', ['%plugin%' => $pluginName]);
            return;
        }

        // Log compatibility warnings but don't block installation
        $currentFsVersion = $this->getFacturaScriptsVersion();
        if (isset($pluginInfo['min_version']) && $pluginInfo['min_version'] > $currentFsVersion) {
            Tools::log()->warning('plugin-incompatible-version', [
                '%plugin%' => $pluginName,
                '%required%' => $pluginInfo['min_version'],
                '%current%' => $currentFsVersion
            ]);
        }

        if (isset($pluginInfo['min_php']) && version_compare(PHP_VERSION, $pluginInfo['min_php'], '<')) {
            Tools::log()->warning('plugin-incompatible-php', [
                '%plugin%' => $pluginName,
                '%required%' => $pluginInfo['min_php'],
                '%current%' => PHP_VERSION
            ]);
        }

        $downloadUrl = $this->github->getDownloadUrl($pluginInfo);
        $tempFile = tempnam(sys_get_temp_dir(), 'plugin_');

        // Download the plugin
        if (!$this->github->downloadPlugin($downloadUrl, $tempFile)) {
            Tools::log()->error('download-failed', ['%plugin%' => $pluginName]);
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            return;
        }

        // Install the plugin
        if (Plugins::add($tempFile, $pluginName . '.zip')) {
            $message = $isUpdate ? 'plugin-updated' : 'plugin-installed';
            Tools::log()->notice($message, ['%plugin%' => $pluginName]);

            // Enable the plugin if it's a new installation
            if (!$isUpdate) {
                Plugins::enable($pluginName);
            }
        } else {
            Tools::log()->error('plugin-install-error', ['%plugin%' => $pluginName]);
        }

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    private function markInstalledPlugins(): void
    {
        $installedPlugins = Plugins::list();
        $currentFsVersion = $this->getFacturaScriptsVersion();

        foreach ($this->pluginList as &$plugin) {
            $plugin['installed'] = false;
            $plugin['update_available'] = false;
            $plugin['compatible'] = true;
            $plugin['compatibility_message'] = '';

            // Check compatibility but don't block installation
            if (isset($plugin['min_version']) && $plugin['min_version'] > $currentFsVersion) {
                $plugin['compatible'] = false;
                $plugin['compatibility_message'] = 'Requires FacturaScripts ' . $plugin['min_version'] . ' or newer (current: ' . $currentFsVersion . ')';
            }

            // Check PHP compatibility
            if (isset($plugin['min_php']) && version_compare(PHP_VERSION, $plugin['min_php'], '<')) {
                $plugin['compatible'] = false;
                if (!empty($plugin['compatibility_message'])) {
                    $plugin['compatibility_message'] .= ' | ';
                }
                $plugin['compatibility_message'] .= 'Requires PHP ' . $plugin['min_php'] . ' or newer (current: ' . PHP_VERSION . ')';
            }

            foreach ($installedPlugins as $installed) {
                if ($installed->name === $plugin['name']) {
                    $plugin['installed'] = true;

                    // Check if update is available
                    if (isset($plugin['version']) && $installed->version < $plugin['version']) {
                        $plugin['update_available'] = true;
                    }
                    break;
                }
            }
        }
    }

    public function getFacturaScriptsVersion(): float
    {
        // Try to get version from constant or config
        // if (defined(Kernel::version())) {
        //     return (float) Kernel::version();
        // }

        // // Fallback to a default version
        // return 2024.96;

        return (float) Kernel::version();
    }
}
