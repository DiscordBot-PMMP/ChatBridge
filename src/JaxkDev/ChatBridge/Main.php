<?php
/*
 * ChatBridge, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020-2021 JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

namespace JaxkDev\ChatBridge;

use JaxkDev\DiscordBot\Plugin\Main as DiscordBot;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\utils\VersionString;
use Phar;

class Main extends PluginBase{

    private DiscordBot $discord;
    private EventListener $listener;

    public function onLoad(){
        $this->checkPrerequisites();
        $this->checkOldEventsFile();
        $this->saveAllResources();
        if(!$this->getConfig()->check()){
            throw new PluginException("Invalid config file, delete config.yml and restart server to restore default config.");
        }
        // $this->updateConfig(); For v1.1 or v2.0 whichever changes config first.
        // $this->verifyConfig(); //Channel/Server ID verification will take place during event handling, ID format can be checked with DiscordBot Utils
        $this->listener = new EventListener($this);
    }

    public function onEnable(){
        $this->getServer()->getPluginManager()->registerEvents($this->listener, $this);
    }

    private function checkOldEventsFile(): void{
        if(is_file($this->discord->getDataFolder()."events.yml")){
            if(is_file($this->getDataFolder()."config.yml")){
                $this->getLogger()->warning("Cannot merge old events.yml file from DiscordBot as config.yml is present, Old events.yml moved from 'DiscordBot/events.yml' to 'ChatBridge/old_events.yml'.");
                if(!rename($this->discord->getDataFolder()."events.yml", $this->getDataFolder()."old_events.yml")){
                    $this->getLogger()->error("Failed to move '{$this->discord->getDataFolder()}events.yml' to '{$this->getDataFolder()}old_events.yml'.");
                }
                return;
            }
            //Create new config.yml with old events.yml data.
            //var_dump(yaml_parse_file($this->discord->getDataFolder()."events.yml"));
            //Use file data to populate config (only if config is not already present)
            //unlink($this->discord->getDataFolder()."events.yml");
        }
    }

    private function saveAllResources(): void{
        $this->saveResource("config.yml");

        //Help files.
        $dir = scandir(Phar::running(true)."/resources/help");
        if($dir === false){
            throw new PluginException("Failed to get help resources internal path.");
        }
        foreach($dir as $file){
            $this->saveResource("help/".$file, true);
        }
    }

    private function checkPrerequisites(): void{
        //Phar
        if(Phar::running() === ""){
            throw new PluginException("Plugin running from source, please use a release phar.");
        }

        //DiscordBot
        $discordBot = $this->getServer()->getPluginManager()->getPlugin("DiscordBot");
        if($discordBot === null) return; //Will never happen.
        if($discordBot->getDescription()->getWebsite() !== "https://github.com/DiscordBot-PMMP/DiscordBot"){
            throw new PluginException("Incompatible dependency 'DiscordBot' detected, see https://github.com/DiscordBot-PMMP/DiscordBot/releases for the correct plugin.");
        }
        $ver = new VersionString($discordBot->getDescription()->getVersion());
        if($ver->getMajor() !== 2){
            throw new PluginException("Incompatible dependency 'DiscordBot' detected, v2.x.y is required however v{$ver->getBaseVersion()}) is installed, see here https://github.com/DiscordBot-PMMP/DiscordBot/releases for downloads.");
        }
        if(!$discordBot instanceof DiscordBot){
            throw new PluginException("Incompatible dependency 'DiscordBot' detected.");
        }
        $this->discord = $discordBot;
    }
}