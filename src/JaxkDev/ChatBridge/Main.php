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

use pocketmine\plugin\PluginBase;

class Main extends PluginBase{

    public function onEnable(){
        if(!$this->checkDependencies()) return;
    }

    private function checkDependencies(): bool{
        if(($discordBot = $this->getServer()->getPluginManager()->getPlugin("DiscordBot")) !== null){
            if($discordBot->getDescription()->getWebsite() !== "https://github.com/DiscordBot-PMMP/DiscordBot"){
                $this->getLogger()->critical("Incompatible dependency 'DiscordBot' detected, see https://github.com/DiscordBot-PMMP/DiscordBot/releases for the plugin.");
                $this->getServer()->getPluginManager()->disablePlugin($this);
                return false;
            }
            if((explode(".", ($ver = $discordBot->getDescription()->getVersion()))[0]??"") !== "2"){
                $this->getLogger()->critical("Incompatible dependency 'DiscordBot' detected, v2.x.y is required, v$ver is available, see releases here https://github.com/DiscordBot-PMMP/DiscordBot/releases for compatible release version.");
                $this->getServer()->getPluginManager()->disablePlugin($this);
                return false;
            }
        }
        return true;
    }
}