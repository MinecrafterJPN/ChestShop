<?php

namespace ChestShop;


use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\item\Block;
use pocketmine\item\Item;

class EventListener implements Listener
{
    private $plugin;

    public function __construct(ChestShop $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onPlayerPlaceBlock(BlockPlaceEvent $event)
    {
        $replacedBlock = $event->getBlockReplaced();
        if ($replacedBlock->getID() === Block::SIGN_POST || $replacedBlock->getID() === Block::WALL_SIGN) {
            
        }
    }

    private function isItem($id)
    {
        if (isset(Item::$list[$id])) return true;
        return false;
    }
} 