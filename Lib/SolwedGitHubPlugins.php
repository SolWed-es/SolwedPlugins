<?php

namespace FacturaScripts\Plugins\SolwedPlugins\Lib;

use Exception;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

class SolwedGitHubPlugins
{
    private string $githubUsername;
    private string $repoName;
    private string $jsonUrl;
    private array $plugins = [];
    private bool $initialized = false;

    public function __construct()
    {
        $this->githubUsername = Tools::settings('solwedplugins', 'github_username', 'SolWed-es');
        $this->repoName = Tools::settings('solwedplugins', 'repo_name', 'SolwedPlugins-container');
        $this->jsonUrl = "https://raw.githubusercontent.com/{$this->githubUsername}/{$this->repoName}/main/plugin-list.json";
    }

    public function getPlugins(): array
    {
        if (!$this->initialized) {
            $this->loadPlugins();
        }
        return $this->plugins;
    }

    public function getPluginInfo(string $pluginName): ?array
    {
        foreach ($this->getPlugins() as $plugin) {
            if ($plugin['name'] === $pluginName) {
                return $plugin;
            }
        }
        return null;
    }

    public function getDownloadUrl(array $pluginInfo): string
    {
        $url = $pluginInfo['download_url'] ??
            "https://github.com/{$this->githubUsername}/{$this->repoName}/releases/download/{$pluginInfo['name']}-v{$pluginInfo['version']}/{$pluginInfo['name']}.zip";
        return $url;
    }

    private function loadPlugins(): void
    {
        try {
            $response = Http::get($this->jsonUrl)
                ->setTimeout(15)
                ->setHeader('User-Agent', 'FacturaScripts-SolwedPlugins/1.0');

            if ($response->failed()) {
                Tools::log()->error('Solicitud HTTP fallida', [
                    '%url%' => $this->jsonUrl,
                    '%status%' => $response->status(),
                    '%error%' => $response->errorMessage()
                ]);
                return;
            }

            $data = json_decode($response->body(), true);

            if (!$data || !isset($data['plugins']) || !is_array($data['plugins'])) {
                Tools::log()->error('Estructura JSON del plugin no válida');
                return;
            }

            $this->plugins = $data['plugins'];
            $this->initialized = true;

            Tools::log()->notice('Plugins extraídos con éxito desde GitHub', [
                '%count%' => count($this->plugins),
                '%url%' => $this->jsonUrl
            ]);
        } catch (Exception $e) {
            Tools::log()->error('Error al cargar los plugins desde GitHub', [
                '%error%' => $e->getMessage(),
                '%url%' => $this->jsonUrl
            ]);
        }
    }

    public function downloadPlugin(string $url, string $destination): bool
    {
        try {
            $response = Http::get($url)
                ->setTimeout(45)
                ->setHeader('User-Agent', 'FacturaScripts-SolwedPlugins/1.0');

            if ($response->failed()) {
                Tools::log()->error('download-failed', [
                    '%url%' => $url,
                    '%status%' => $response->status(),
                    '%error%' => $response->errorMessage()
                ]);
                return false;
            }

            $content = $response->body();

            if (empty($content)) {
                Tools::log()->error('Contenido descargado vacío', ['%url%' => $url]);
                return false;
            }

            $result = file_put_contents($destination, $content);

            if ($result === false) {
                Tools::log()->error('Error al escribir el archivo', ['%destination%' => $destination]);
                return false;
            }

            $sizeKb = round(strlen($content) / 1024, 2);
            Tools::log()->notice('Descarga exitosa', [
                '%url%' => $url,
                '%size%' => $sizeKb . 'KB'
            ]);

            return true;
        } catch (Exception $e) {
            Tools::log()->error('Excepción durante la descarga', [
                '%url%' => $url,
                '%error%' => $e->getMessage()
            ]);
            return false;
        }
    }
}
