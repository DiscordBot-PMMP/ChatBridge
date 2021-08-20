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

use JaxkDev\DiscordBot\Models\Messages\Message;
use JaxkDev\DiscordBot\Plugin\ApiRejection;
use JaxkDev\DiscordBot\Plugin\ApiResolution;
use JaxkDev\DiscordBot\Plugin\Main as DiscordBot;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\VersionString;

class Main extends PluginBase{

    private DiscordBot $discord;

    public function onEnable(){
        if(!$this->checkDependencies()) return;
    }

    private function checkDependencies(): bool{
        $discordBot = $this->getServer()->getPluginManager()->getPlugin("DiscordBot");
        if($discordBot === null) return false; //Will never happen.
        if($discordBot->getDescription()->getWebsite() !== "https://github.com/DiscordBot-PMMP/DiscordBot"){
            $this->getLogger()->critical("Incompatible dependency 'DiscordBot' detected, see https://github.com/DiscordBot-PMMP/DiscordBot/releases for the correct plugin.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        $ver = new VersionString($discordBot->getDescription()->getVersion());
        if($ver->getMajor() !== 2){
            $this->getLogger()->critical("Incompatible dependency 'DiscordBot' detected, v2.x.y is required however v{$ver->getBaseVersion()}) is installed, see here https://github.com/DiscordBot-PMMP/DiscordBot/releases for downloads.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        if(!$discordBot instanceof DiscordBot){
            $this->getLogger()->critical("Incompatible dependency 'DiscordBot' detected.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        $this->discord = $discordBot;
        return true;
    }
}