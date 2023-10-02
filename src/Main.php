<?php
/*
 * ChatBridge, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020-present JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

namespace JaxkDev\ChatBridge;

use JaxkDev\DiscordBot\Plugin\Main as DiscordBot;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\utils\TextFormat;
use pocketmine\utils\VersionString;
use Phar;

class Main extends PluginBase{

    private DiscordBot $discord;

    public function onLoad(): void{
        $this->checkPrerequisites();
        $this->saveAllResources();
        // TODO Option for showing console commands in Discord not just player executed commands.
    }

    public function onEnable(): void{
        if(!$this->loadConfig()){
            return;
        }
        $listener = new EventListener($this);
        $this->getServer()->getPluginManager()->registerEvents($listener, $this);
    }

    private function saveAllResources(): void{
        //Help files.
        $dir = scandir(Phar::running() . "/resources/help");
        if($dir === false){
            throw new PluginException("Failed to get help resources internal path.");
        }
        foreach($dir as $file){
            $this->saveResource("help/" . $file, true);
        }
    }

    private function checkPrerequisites(): void{
        //Phar
        if(Phar::running() === ""){
            throw new PluginException("Plugin running from source, please use a release phar.");
        }

        //DiscordBot
        $discordBot = $this->getServer()->getPluginManager()->getPlugin("DiscordBot");
        if($discordBot === null){
            return; //Will never happen.
        }
        if($discordBot->getDescription()->getWebsite() !== "https://github.com/DiscordBot-PMMP/DiscordBot"){
            throw new PluginException("Incompatible dependency 'DiscordBot' detected, see https://github.com/DiscordBot-PMMP/DiscordBot/releases for the correct plugin.");
        }
        $ver = new VersionString($discordBot->getDescription()->getVersion());
        if($ver->getMajor() !== 3){
            throw new PluginException("Incompatible dependency 'DiscordBot' detected, v3.x.y is required however v{$ver->getBaseVersion()}) is installed, see https://github.com/DiscordBot-PMMP/DiscordBot/releases for downloads.");
        }
        if(!$discordBot instanceof DiscordBot){
            throw new PluginException("Incompatible dependency 'DiscordBot' detected.");
        }
        $this->discord = $discordBot;
    }

    private function loadConfig(): bool{
        $this->getLogger()->debug("Loading configuration...");

        /** @var array<string, mixed> $config */
        $config = yaml_parse_file($this->getDataFolder()."config.yml");
        if(!$config or !is_int($config["version"]??"")){
            $this->getLogger()->critical("Failed to parse config.yml");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        $this->getLogger()->debug("Config loaded, version: ".$config["version"]);

        if(intval($config["version"]) !== ConfigUtils::VERSION){
            $old = $config["version"];
            $this->getLogger()->info("Updating your config from v".$old." to v".ConfigUtils::VERSION);
            ConfigUtils::update($config);
            rename($this->getDataFolder()."config.yml", $this->getDataFolder()."config.yml.v".$old);
            yaml_emit_file($this->getDataFolder()."config.yml", $config);
            $this->getLogger()->notice("Config updated, old config was saved to '{$this->getDataFolder()}config.yml.v".$old."'");
        }

        $this->getLogger()->debug("Verifying config...");
        $result_raw = ConfigUtils::verify($config);
        if(sizeof($result_raw) !== 0){
            $result = TextFormat::RED."There were some problems with your config.yml, see below:\n".TextFormat::RESET;
            foreach($result_raw as $value){
                $result .= "$value\n";
            }
            $this->getLogger()->error(rtrim($result));
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        $this->getLogger()->debug("Config verified.");

        //Config is now updated and verified.
        return true;
    }

    public function getDiscord(): DiscordBot{
        return $this->discord;
    }
}
