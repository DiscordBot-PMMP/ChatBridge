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

use JaxkDev\DiscordBot\Plugin\Events\DiscordClosed;
use JaxkDev\DiscordBot\Plugin\Events\DiscordReady;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\utils\Config;

class EventListener implements Listener{

    private bool $ready = false;
    private Main $plugin;
    private Config $config;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        $this->config = $this->plugin->getConfig();
    }

    public function onDiscordReady(DiscordReady $event): void{
        $this->ready = true;
    }

    public function onDiscordClosed(DiscordClosed $event): void{
        //This plugin can no longer function if discord closes, note once closed it will never start again until server restarts.
        $this->plugin->getLogger()->critical("DiscordBot has closed, disabling plugin.");
        $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
    }

    // -- Check for minecraft messages and send to discord --
    public function onMinecraftMessage(PlayerChatEvent $event): void{
        if(!$this->ready){
            //TODO, Should we stack the messages up and send all 3/s when discords ready or....
            $this->plugin->getLogger()->debug("Ignoring chat event, discord is not ready.");
            return;
        }

        $config = $this->config->getNested("messages.minecraft");
        if(!$config['enabled']) return;

        $player = $event->getPlayer();
        $world = $player->getLevelNonNull()->getName();

    }
}