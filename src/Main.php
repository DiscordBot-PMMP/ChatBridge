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
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\utils\VersionString;
use Phar;

class Main extends PluginBase{

    /** @var DiscordBot */
    private $discord;

    /** @var EventListener */
    private $listener;

    public function onLoad(): void{
        $this->checkPrerequisites();
        $this->checkOldEventsFile();
        $this->saveAllResources();
        // TODO Channel/Server ID verification will take place during event handling, ID format can be checked with DiscordBot Utils
        // TODO Option for showing console commands in Discord not just player executed commands.
        $this->listener = new EventListener($this);
    }

    public function onEnable(): void{
        if($this->getServer()->getPluginManager()->getPlugin("DiscordBot")?->isEnabled() !== true){
            $this->getLogger()->critical("DiscordBot is not enabled! Dependency must be enabled for the plugin to operate.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        if(!$this->loadConfig()){
            return;
        }
        $this->getServer()->getPluginManager()->registerEvents($this->listener, $this);
    }

    private function saveAllResources(): void{
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
        if($discordBot === null){
            return; //Will never happen.
        }
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

    private function loadConfig(): bool{
        $this->getLogger()->debug("Loading configuration...");

        /** @var array<string, mixed> $config */
        $config = $this->getConfig()->getAll();
        if($config === [] or !is_int($config["version"]??"")){
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
            $this->getConfig()->setAll($config);
            $this->getConfig()->save();
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
