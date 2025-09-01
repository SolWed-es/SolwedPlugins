<?php

namespace FacturaScripts\Plugins\SolwedPlugins\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\SolwedPlugins\Lib\SolwedGitHubPlugins;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Un controlador es básicamente una página o una opción del menú de FacturaScripts.
 *
 * https://facturascripts.com/publicaciones/los-controladores-410
 */
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

        $downloadUrl = $this->github->getDownloadUrl($pluginInfo);
        $tempFile = tempnam(sys_get_temp_dir(), 'plugin_');

        // Download the plugin
        if (!$this->github->downloadPlugin($downloadUrl, $tempFile)) {
            Tools::log()->error('download-failed', ['%plugin%' => $pluginName]);
            unlink($tempFile);
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

        unlink($tempFile);
    }

    private function markInstalledPlugins(): void
    {
        $installedPlugins = Plugins::list();

        foreach ($this->pluginList as &$plugin) {
            $plugin['installed'] = false;
            $plugin['update_available'] = false;

            foreach ($installedPlugins as $installed) {
                if ($installed->name === $plugin['name']) {
                    $plugin['installed'] = true;

                    // Check if update is available
                    if ($installed->version < $plugin['version']) {
                        $plugin['update_available'] = true;
                    }
                    break;
                }
            }
        }
    }
}
