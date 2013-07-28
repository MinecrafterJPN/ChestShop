<?php

/*
 __PocketMine Plugin__
name=ChestShop
description=You can run your chest shop and purchase from others' chest shop.
version=1.5.1
author=MinecrafterJPN
class=ChestShop
apiversion=9
*/

class ChestShop implements Plugin
{
	private $api, $path;
	
	const CHEST_SLOTS = 27;

	public function __construct(ServerAPI $api, $server = false)
	{
		$this->api = $api;
	}

	public function init()
	{
		$this->api->addHandler("tile.update", array($this, "eventHandler"));
		$this->api->addHandler("player.block.touch", array($this, "eventHandler"));
		$this->path = $this->api->plugin->createConfig($this, array());
	}

	public function eventHandler(&$data, $event)
	{
		switch($event)
		{
			case "tile.update":
				if($data->class === TILE_SIGN)
				{
					$shopOwner = $data->data['creator'];
					$saleNum = $data->data['Text2'];
					$price = $data->data['Text3'];
					$productID = $this->isItem($data->data['Text4']);

					if($data->data['Text1'] !== "") break;
					if(!is_numeric($saleNum) or $saleNum <= 0) break;
					if(!is_numeric($price) or $price < 0) break;
					if($productID === false) break;

					$chest = $this->getSideChest($data);
					if($chest === false) break;
					if(strlen($shopOwner) > 15) $shopOwner = substr($shopOwner, 0, 15);

					$data->data['Text1'] = $shopOwner;
					$data->data['Text2'] = "Amount:$saleNum";
					$data->data['Text3'] = "Price:$price";

					$newConfig = array(
							array(
									'shopOwner' => $shopOwner,
									'saleNum' => $saleNum,
									'price' => $price,
									'productID' => $productID,
									'signX' => $data->x,
									'signY' => $data->y,
									'signZ' => $data->z,
									'chestX' => $chest->x,
									'chestY' => $chest->y,
									'chestZ' => $chest->z
							)
					);
					$this->overwriteConfig($newConfig);
				}
				break;
			case "player.block.touch":
				$tile = $this->api->tile->get(new Position($data['target']->x, $data['target']->y, $data['target']->z, $data['target']->level));
				if($tile === false) break;
				$class = $tile->class;
				$cfg = $this->api->plugin->readYAML($this->path . "config.yml");
				switch($class)
				{
					case TILE_SIGN:

						switch($data['type'])
						{
							case "place":
								$shopInfo = false;
								foreach ($cfg as $val)
								{
									if($data['target']->x === $val['signX']
											and $data['target']->y === $val['signY']
											and $data['target']->z === $val['signZ'])
									{
										$c = $this->getSideChest($data['target']);
										if($c === false) break;
										if($val['chestX'] === $c->x and $val['chestY'] === $c->y and $val['chestZ'] === $c->z)
										{
											$shopInfo = $val;
											break;
										}
									}
								}
								if($shopInfo === false) break;
								if($shopInfo['shopOwner'] === $data['player']->username)
								{
									$this->api->chat->sendTo(false, "[ChestShop]Cannot purchase from your own shop.", $data['player']->username);
									break;
								}
								$this->startTransaction($data['player']->username, $shopInfo, $c);
								break;
							case "break":
								foreach ($cfg as $val)
								{
									if($val['signX'] === $data['target']->x
											and $val['signY'] === $data['target']->y
											and $val['signZ'] === $data['target']->z)
									{
										if($val['shopOwner'] !== $data['player']->username)
										{
											$this->api->chat->sendTo(false, "[ChestShop]This sign is protected.", $data['player']->username);
											return false;
										}
									}
								}
								break;
						}
						break;
					case TILE_CHEST:
						foreach ($cfg as $val)
						{
							if($val['chestX'] === $data['target']->x
									and $val['chestY'] === $data['target']->y
									and $val['chestZ'] === $data['target']->z
									and $val['shopOwner'] !== $data['player']->username)
							{
								$this->api->chat->sendTo(false, "[ChestShop]This chest is protected.", $data['player']->username);
								return false;
							}
						}
						break;
				}
		}
	}

	private function getSideChest($data)
	{
		$item = $data->level->getBlock(new Vector3($data->x + 1, $data->y, $data->z));
		if($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x - 1, $data->y, $data->z));
		if($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x, $data->y, $data->z + 1));
		if($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x, $data->y, $data->z - 1));
		if($item->getID() === CHEST) return $item;
		return false;
	}

	private function isItem($item)
	{
		$tmp = strtolower($item);
		if(isset($this->blocks[$tmp])) return $item;
		if(isset($this->items[$tmp])) return $item;
		if(($id = array_search($tmp, $this->blocks)) !== false) return $id;
		if(($id = array_search($tmp, $this->items)) !== false) return $id;
		return false;
	}

	private function startTransaction($username, $shopInfo, $c)
	{
		if(!file_exists("./plugins/PocketMoney/config.yml"))
		{
			$this->api->chat->sendTo(false, "[ChestShop][Error]PocketMoney plugin has not been loaded.", $username);
			console("[ChestShop][Error]PocketMoney plugin has not been loaded.");
			return;
		}
		$buyerMoney = $this->api->dhandle("money.player.get", array('username' => $username));
		if($buyerMoney === false) return;
		if($buyerMoney < $shopInfo['price'])
		{
			$this->api->chat->sendTo(false, "[ChestShop]Your money is not enough.", $username);
			return;
		}
		$chest = $this->api->tile->get(new Position($c->x, $c->y, $c->z, $c->level));
		$saleNum = 0;
		for ($i = 0; $i < self::CHEST_SLOTS; $i++)
		{
			$item = $chest->getSlot($i);
			if($item->getID() === $shopInfo['productID'])
			{
				$saleNum += $item->count;
			}
		}
		if($saleNum < $shopInfo['saleNum'])
		{
			$this->api->chat->sendTo(false, "[ChestShop]The stock is not enough.", $username);
			$this->api->chat->sendTo(false, "[ChestShop]Please notify the owner of the lack.", $username);
			return;
		}
		$cmd = "give";
		$params = array($username, $shopInfo['productID'], $shopInfo['saleNum']);
		$issuer = $this->api->player->get($username);
		$alias = false;
		$this->api->block->commandHandler($cmd, $params, $issuer, $alias);
		$tmpNum = $shopInfo['saleNum'];
		for ($i = 0; $i < self::CHEST_SLOTS; $i++)
		{
			$item = $chest->getSlot($i);
			if($item->getID() === $shopInfo['productID'])
			{
				if($item->count <= $tmpNum)
				{
					$chest->setSlot($i, BlockAPI::getItem(AIR, 0, 0));
					$tmpNum -= $item->count;
				}
				else
				{
					$count = $item->count - $tmpNum;
					$chest->setSlot($i, BlockAPI::getItem($item->getID(), 0, $count));
					break;
				}
			}
		}
		$this->api->dhandle("money.handle", array(
				'username' => $username,
				'method' => 'grant',
				'amount' => -$shopInfo['price']
		));
		$this->api->dhandle("money.handle", array(
				'username' => $shopInfo['shopOwner'],
				'method' => 'grant',
				'amount' => $shopInfo['price']
		));
		$this->api->chat->sendTo(false, "[ChestShop]Completed the transaction.", $username);
		return;
	}

	private function overwriteConfig($dat)
	{
		$cfg = array();
		$cfg = $this->api->plugin->readYAML($this->path . "config.yml");
		$result = array_merge($cfg, $dat);
		$this->api->plugin->writeYAML($this->path."config.yml", $result);
	}

	public function __destruct()
	{
	}

	private $blocks = array(
			0 => "air",
			1 => "stone",
			2 => "grass",
			3 => "dirt",
			4 => "cobblestone",
			5 => "woodenplank",
			6 => "sapling",
			7 => "bedrock",
			8 => "water",
			9 => "stationarywater",
			10 => "lava",
			11 => "stationarylava",
			12 => "sand",
			13 => "gravel",
			14 => "goldore",
			15 => "ironore",
			16 => "coalore",
			17 => "wood",
			18 => "leaves",
			19 => "sponge",
			20 => "glass",
			21 => "lapislazuliore",
			22 => "lapislazuliblock",
			23 => "dispenser",
			24 => "sandstone",
			25 => "noteblock",
			26 => "bed",
			27 => "poweredrail",
			28 => "detectorrail",
			29 => "stickypiston",
			30 => "cobweb",
			31 => "tallgrass",
			32 => "deadshrub",
			35 => "wool",
			37 => "yellowflower",
			38 => "cyanflower",
			39 => "brownmushroom",
			40 => "redmushroom",
			41 => "blockofgold",
			42 => "blockofiron",
			44 => "stoneslab",
			45 => "brick",
			46 => "tnt",
			47 => "bookcase",
			48 => "mossstone",
			49 => "obsidian",
			50 => "torch",
			51 => "fire",
			52 => "mobspawner",
			53 => "woodenstairs",
			56 => "diamondore",
			57 => "blockofdiamond",
			58 => "workbench",
			59 => "wheat",
			60 => "farmland",
			61 => "furnace",
			62 => "furnace",
			64 => "wooddoor",
			65 => "ladder",
			66 => "rails",
			67 => "cobblestonestairs",
			71 => "irondoor",
			73 => "redstoneore",
			78 => "snow",
			79 => "ice",
			80 => "snowblock",
			81 => "cactus",
			82 => "clayblock",
			83 => "sugarcane",
			85 => "fence",
			87 => "netherrack",
			88 => "soulsand",
			89 => "glowstone",
			96 => "trapdoor",
			98 => "stonebricks",
			99 => "brownmushroom",
			100 => "redmushroom",
			102 => "glasspane",
			103 => "melon",
			105 => "melonvine",
			107 => "fencegate",
			108 => "brickstairs",
			109 => "stonebrickstairs",
			112 => "netherbrick",
			114 => "netherbrickstairs",
			128 => "sandstonestairs",
			155 => "blockofquartz",
			156 => "quartzstairs",
			245 => "stonecutter",
			246 => "glowingobsidian",
			247 => "netherreactorcore"
	);
	private $items = array(
			256 => "ironshovel",
			257 => "ironpickaxe",
			258 => "ironaxe",
			259 => "flintandsteel",
			260 => "apple",
			261 => "bow",
			262 => "arrow",
			263 => "coal",
			264 => "diamondgem",
			265 => "ironingot",
			266 => "goldingot",
			267 => "ironsword",
			268 => "woodensword",
			269 => "woodenshovel",
			270 => "woodenpickaxe",
			271 => "woodenaxe",
			272 => "stonesword",
			273 => "stoneshovel",
			274 => "stonepickaxe",
			275 => "stoneaxe",
			276 => "diamondsword",
			277 => "diamondshovel",
			278 => "diamondpickaxe",
			279 => "diamondaxe",
			280 => "stick",
			281 => "bowl",
			282 => "mushroomstew",
			283 => "goldsword",
			284 => "goldshovel",
			285 => "goldpickaxe",
			286 => "goldaxe",
			287 => "string",
			288 => "feather",
			289 => "gunpowder",
			290 => "woodenhoe",
			291 => "stonehoe",
			292 => "ironhoe",
			293 => "diamondhoe",
			294 => "goldhoe",
			295 => "wheatseeds",
			296 => "wheat",
			297 => "bread",
			298 => "leatherhelmet",
			299 => "leatherchestplate",
			300 => "leatherleggings",
			301 => "leatherboots",
			302 => "chainmailhelmet",
			303 => "chainmailchestplate",
			304 => "chainmailleggings",
			305 => "chainmailboots",
			306 => "ironhelmet",
			307 => "ironchestplate",
			308 => "ironleggings",
			309 => "ironboots",
			310 => "diamondhelmet",
			311 => "diamondchestplate",
			312 => "diamondleggings",
			313 => "diamondboots",
			314 => "goldhelmet",
			315 => "goldchestplate",
			316 => "goldleggings",
			317 => "goldboots",
			318 => "flint",
			319 => "rawporkchop",
			320 => "cookedporkchop",
			321 => "painting",
			322 => "goldapple",
			323 => "sign",
			332 => "snowball",
			334 => "leather",
			336 => "claybrick",
			337 => "clay",
			338 => "sugarcane",
			339 => "paper",
			340 => "book",
			344 => "egg",
			348 => "glowstonedust",
			352 => "bone",
			353 => "sugar",
			355 => "bed",
			359 => "shears",
			360 => "melon",
			362 => "melonseeds",
			363 => "rawbeef",
			364 => "steak",
			365 => "rawchicken",
			366 => "cookedchicken",
			405 => "netherbrick",
			456 => "camera"
	);
}