<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Model\Cache;
use daita\MySmallPhpTools\Model\CacheItem;
use OCA\Social\AP;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\NoteNotFoundException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RequestContentException;
use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Exceptions\RequestResultNotJsonException;
use OCA\Social\Exceptions\RequestResultSizeException;
use OCA\Social\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamQueue;


/**
 * Class StreamQueueService
 *
 * @package OCA\Social\Service
 */
class StreamQueueService {


	/** @var NotesRequest */
	private $notesRequest;

	/** @var StreamQueueRequest */
	private $streamQueueRequest;

	private $importService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * StreamQueueService constructor.
	 *
	 * @param NotesRequest $notesRequest
	 * @param StreamQueueRequest $streamQueueRequest
	 * @param ImportService $importService
	 * @param CurlService $curlService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NotesRequest $notesRequest, StreamQueueRequest $streamQueueRequest,
		ImportService $importService, CurlService $curlService, MiscService $miscService
	) {
		$this->notesRequest = $notesRequest;
		$this->streamQueueRequest = $streamQueueRequest;
		$this->importService = $importService;
		$this->curlService = $curlService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $token
	 * @param string $type
	 * @param string $streamId
	 */
	public function generateStreamQueue(string $token, string $type, string $streamId) {
		$cache = new StreamQueue($token, $type, $streamId);

		$this->streamQueueRequest->create($cache);
	}


	/**
	 * @param int $total
	 *
	 * @return StreamQueue[]
	 */
	public function getRequestStandby(int &$total = 0): array {
		$queue = $this->streamQueueRequest->getStandby();
		$total = sizeof($queue);

		$result = [];
		foreach ($queue as $request) {
			$delay = floor(pow($request->getTries(), 4) / 3);
			if ($request->getLast() < (time() - $delay)) {
				$result[] = $request;
			}
		}

		return $result;
	}


	/**
	 * @param string $token
	 */
	public function cacheStreamByToken(string $token) {
		$this->miscService->log('Cache: ' . $token);
		$items = $this->streamQueueRequest->getFromToken($token);

		foreach ($items as $item) {
			$this->manageStreamQueue($item);
		}
	}


	/**
	 * @param StreamQueue $queue
	 */
	public function manageStreamQueue(StreamQueue $queue) {

		try {
//			$this->initRequest($queue);
		} catch (QueueStatusException $e) {
			return;
		}

		switch ($queue->getType()) {
			case 'Cache':
				$this->manageStreamQueueCache($queue);
				break;

			default:
				$this->deleteRequest($queue);
				break;
		}
	}


	private function manageStreamQueueCache(StreamQueue $queue) {
		try {
			$stream = $this->notesRequest->getNoteById($queue->getStreamId());
		} catch (NoteNotFoundException $e) {
			$this->deleteRequest($queue);

			return;
		}

		if (!$stream->gotCache()) {
			$this->deleteRequest($queue);

			return;
		}

		$this->manageStreamCache($stream);
	}


	/**
	 * @param Stream $stream
	 */
	private function manageStreamCache(Stream $stream) {
		$cache = $stream->getCache();
		foreach ($cache->getItems() as $item) {
			$this->cacheItem($item);
			$cache->updateItem($item);
		}

		$this->updateCache($stream, $cache);
	}


	/**
	 * @param CacheItem $item
	 */
	private function cacheItem(CacheItem &$item) {

		try {
			$data = $this->curlService->retrieveObject($item->getUrl());
			$object = AP::$activityPub->getItemFromData($data);

			$origin = parse_url($item->getUrl(), PHP_URL_HOST);
			$object->setOrigin($origin, SignatureService::ORIGIN_REQUEST, time());

			if ($object->getId() !== $item->getUrl()) {
				throw new InvalidOriginException();
			}

			if ($object->getType() !== Note::TYPE) {
				throw new InvalidResourceException();
			}

			$interface = AP::$activityPub->getInterfaceForItem($object);
			$interface->save($object);

			$note = $this->notesRequest->getNoteById($object->getId());
			$item->setContent(json_encode($note, JSON_UNESCAPED_SLASHES));

//$this->endRequest($queue, true);

		} catch (NoteNotFoundException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->deleteRequest($queue);
		} catch (InvalidOriginException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->deleteRequest($queue);
		} catch (RequestContentException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->deleteRequest($queue);
		} catch (RequestNetworkException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->endRequest($queue, false);
		} catch (RequestResultNotJsonException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->endRequest($queue, false);
		} catch (RequestResultSizeException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->deleteRequest($queue);
		} catch (RequestServerException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->endRequest($queue, false);
		} catch (MalformedArrayException $e) {
//$this->deleteRequest($item);
		} catch (ItemUnknownException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->deleteRequest($item);
		} catch (RedundancyLimitException $e) {
			$this->miscService->log(
				'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
				. $e->getMessage(), 1
			);
//			$this->deleteRequest($item);
		} catch (SocialAppConfigException $e) {
		}

	}


	/**
	 * @param Stream $stream
	 * @param Cache $cache
	 */
	private function updateCache(Stream $stream, Cache $cache) {
		$this->notesRequest->updateCache($stream, $cache);
	}


	/**
	 * @param StreamQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function initRequest(StreamQueue $queue) {
		$this->streamQueueRequest->setAsRunning($queue);
	}


	/**
	 * @param StreamQueue $queue
	 * @param bool $success
	 */
	public function endRequest(StreamQueue $queue, bool $success) {
		try {
			if ($success === true) {
				$this->streamQueueRequest->setAsSuccess($queue);
			} else {
				$this->streamQueueRequest->setAsFailure($queue);
			}
		} catch (QueueStatusException $e) {
		}
	}


	/**
	 * @param StreamQueue $queue
	 */
	public function deleteRequest(StreamQueue $queue) {
//		try {
//			$stream = $this->notesRequest->getNoteById($queue->getStreamId());
//			$cache = $stream->getCache();

//			$cache->removeItem($queue->get)
//		} catch (NoteNotFoundException $e) {
//		}

		$this->streamQueueRequest->delete($queue);
	}


}
