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
use pocketmine\utils\Config;
use pocketmine\utils\VersionString;
use Phar;

class Main extends PluginBase{

    /** @var DiscordBot */
    private $discord;

    /** @var EventListener */
    private $listener;

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
            throw new PluginException("Incompatible dependency 'DiscordBot' detected, v2.x.y is required however v{$ver->getBaseVersion()}) is installed, see https://github.com/DiscordBot-PMMP/DiscordBot/releases for downloads.");
        }
        if(!$discordBot instanceof DiscordBot){
            throw new PluginException("Incompatible dependency 'DiscordBot' detected.");
        }
        $this->discord = $discordBot;
    }

    //Remove in v2.0
    private function checkOldEventsFile(): void{
        $convertOldID = function(array $ids): array{
            $new = [];
            foreach($ids as $id){
                $new[] = str_contains($id, ".") ? explode(".", $id)[1] : $id;
            }
            return $new;
        };
        if(is_file($this->discord->getDataFolder()."events.yml")){
            if(is_file($this->getDataFolder()."config.yml")){
                $this->getLogger()->warning("Cannot merge old events.yml file from DiscordBot as config.yml is present, Old events.yml moved from 'DiscordBot/events.yml' to 'ChatBridge/old_events.yml'.");
                if(!rename($this->discord->getDataFolder()."events.yml", $this->getDataFolder()."old_events.yml")){
                    $this->getLogger()->error("Failed to move '{$this->discord->getDataFolder()}events.yml' to '{$this->getDataFolder()}old_events.yml'.");
                }else{
                    $this->getLogger()->debug("Moved '{$this->discord->getDataFolder()}events.yml' to '{$this->getDataFolder()}old_events.yml'.");
                }
                return;
            }
            $this->saveResource("config.yml");
            $old = new Config($this->discord->getDataFolder()."events.yml");
            if((intval($old->get("version", 0))) !== 1){
                $this->getLogger()->error("Failed to convert DiscordBot's old events.yml to ChatBridge config.yml");
                if(!rename($this->discord->getDataFolder()."events.yml", $this->getDataFolder()."old_events.yml")){
                    $this->getLogger()->error("Failed to move '{$this->discord->getDataFolder()}events.yml' to '{$this->getDataFolder()}old_events.yml'.");
                }else{
                    $this->getLogger()->debug("Moved '{$this->discord->getDataFolder()}events.yml' to '{$this->getDataFolder()}old_events.yml'.");
                }
                return;
            }
            $cfg = $this->getConfig();
            $cfg->setNested("messages.discord.from_channels", $convertOldID((array)$old->getNested("message.fromDiscord.channels", ["123456789"])));
            $cfg->setNested("messages.discord.format", $old->getNested("message.fromDiscord.format", "[Discord] §a{NICKNAME}§r > §c{MESSAGE}"));
            $cfg->setNested("messages.minecraft.format.text", $old->getNested("message.toDiscord.format", "New Message"));
            $cfg->setNested("messages.minecraft.to_discord_channels", $convertOldID((array)$old->getNested("message.toDiscord.channels", ["123456789"])));

            //Replace {COMMAND} to have same behaviour as DiscordBot v1
            $cfg->setNested("commands.minecraft.format.text", str_replace("{COMMAND}", "/{COMMAND} {ARGS}", strval($old->getNested("command.toDiscord.format", "Command executed"))));
            $cfg->setNested("commands.minecraft.to_discord_channels", $convertOldID((array)$old->getNested("command.toDiscord.channels", ["123456789"])));

            $cfg->setNested("leave.discord.format", $old->getNested("member_leave.fromDiscord.format", "§a{NICKNAME} §cHas left the discord server :("));
            $cfg->setNested("leave.minecraft.format.text", $old->getNested("member_leave.toDiscord.format", "Player Left"));
            $cfg->setNested("leave.minecraft.to_discord_channels", $convertOldID((array)$old->getNested("member_leave.toDiscord.channels", ["123456789"])));

            $cfg->setNested("join.discord.format", $old->getNested("member_join.fromDiscord.format", "§a{USERNAME} §cHas joined the discord server :)"));
            $cfg->setNested("join.minecraft.format.text", $old->getNested("member_join.toDiscord.format", "Player Joined"));
            $cfg->setNested("join.minecraft.to_discord_channels", $convertOldID((array)$old->getNested("member_join.toDiscord.channels", ["123456789"])));

            $cfg->save();
            $this->getLogger()->notice("Old DiscordBot events.yml has been migrated to ChatBridge's config.yml, review the new configuration at '{$this->getDataFolder()}config.yml' ");
            if(!rename($this->discord->getDataFolder()."events.yml", $this->getDataFolder()."old_events.yml")){
                $this->getLogger()->error("Failed to move '{$this->discord->getDataFolder()}events.yml' to '{$this->getDataFolder()}old_events.yml'.");
            }else{
                $this->getLogger()->debug("Moved '{$this->discord->getDataFolder()}events.yml' to '{$this->getDataFolder()}old_events.yml'.");
            }
        }
    }

    public function getDiscord(): DiscordBot{
        return $this->discord;
    }
}