<?php

namespace ChestShop;

use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\math\Vector3;

class EventListener implements Listener
{
    private $plugin;
    private $dataBaseManager;

    public function __construct(ChestShop $plugin, DataBaseManager $dbm)
    {
        $this->plugin = $plugin;
        $this->dataBaseManager = $dbm;
    }

    public function onPlayerPlaceBlock(BlockPlaceEvent $event)
    {
        $item = $event->getItem();
        $this->plugin->getLogger()->info($item->getID());
        if ($item->getID() === Block::SIGN_POST || $item->getID() === Block::WALL_SIGN) {
        }
        return true;
    }

    public function onSignChange(SignChangeEvent $event)
    {
        $shopOwner = $event->getPlayer()->getName();
        $saleNum = $event->getLine(1);
        $price = $event->getLine(2);
        $productData = explode(":", $event->getLine(3));
        $pID = $this->isItem($id = array_shift($productData)) ? $id : false;
        $pMeta = ($meta = array_shift($productData)) ? $meta : 0;

        $sign = $event->getBlock();

        if ($event->getLine(0) !== "") return;
        if (!is_numeric($saleNum) or $saleNum <= 0) return;
        if (!is_numeric($price) or $price < 0) return;
        if ($pID === false) return;
        if ($chest = $this->getSideChest($sign)) return;

        $productName = isset(Block::$list[$pID]) ? Block::$list[$pID]->getName() : Item::$list[$pID]->getName();
        $event->setLine(0, $shopOwner);
        $event->setLine(1, "Amount:$saleNum");
        $event->setLine(2, "Price:$price");
        $event->setLine(3, "$productName:$pMeta");

        $this->dataBaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest);
    }

    private function getSideChest(Position $pos)
    {
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1));
        if ($block->getID() === Block::CHEST) return $block;
        return false;
    }

    private function isItem($id)
    {
        if (isset(Item::$list[$id])) return true;
        return false;
    }
} 