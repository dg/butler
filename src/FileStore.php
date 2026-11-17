<?php declare(strict_types=1);

namespace Butler;

use function count, strlen;
use const LOCK_EX, LOCK_NB;


/**
 * Store in a directory. Per task, named by its class with backslashes replaced by dots:
 *   <task>.lock       lock of the processing
 *   <task>.marker     pending trigger of a Task: "<pending since> <token of the last trigger>"
 *   <task>.queue/     pending payloads of a PayloadTask, "<time of arrival>-<random>.<attempts>.json"
 *   <task>.rejected/  payloads that ran too many times or cannot be read
 * Times are in UTC. The directory must survive deploys and must not be emptied with the cache.
 */
final readonly class FileStore implements Store
{
	private const TimeFormat = 'Ymd-His-u';


	public function __construct(
		private string $dir,
		private int $maxQueueSize = 1000,
		private int $maxAttempts = 10,
	) {
	}


	public function lock(string $task, bool $wait): ?Lock
	{
		$handle = $this->openFile($this->getPath($task, '.lock'));
		if (!flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB, $wouldBlock)) {
			fclose($handle);
			return $wouldBlock ? null : throw new IOException("Unable to lock the processing of $task.");
		}

		return new Lock(function () use ($handle): void {
			flock($handle, LOCK_UN);
			fclose($handle);
		});
	}


	public function markPending(string $task): void
	{
		$this->updateMarker($task, function (string $content): string {
			$since = $content === '' ? $this->formatNow() : explode(' ', $content)[0];
			return $since . ' ' . bin2hex(random_bytes(8));
		});
	}


	public function enqueue(string $task, Payload $payload): void
	{
		$dir = $this->getPath($task, '.queue');
		$this->createDir($dir);
		if (count($this->getQueueFiles($task)) >= $this->maxQueueSize) {
			throw new QueueFullException("The queue of $task is full.");
		}

		$json = json_encode([
			'body' => base64_encode($payload->body),
			'headers' => $payload->headers,
			'query' => $payload->query,
		], JSON_THROW_ON_ERROR);
		$this->writeFile($dir . '/' . $this->formatNow() . '-' . bin2hex(random_bytes(4)) . '.0.json', $json);
	}


	public function peek(string $task): ?Entry
	{
		$marker = $this->readMarker($task);
		if ($marker !== '') {
			[$since, $token] = explode(' ', $marker);
			return new Entry($task, $token, $this->parseTime($since));
		}

		foreach ($this->getQueueFiles($task) as $file) {
			$json = @file_get_contents($file); // @ the file may have been processed by another process meanwhile
			if ($json === false) {
				continue;
			}

			$entry = $this->createEntry($task, $file, $json);
			if ($entry) {
				return $entry;
			}
			$this->moveFile($file, $this->getPath($task, '.rejected') . '/' . basename($file));
		}

		return null;
	}


	public function startAttempt(Entry $entry): ?Entry
	{
		if ($entry->payload === null) {
			return $entry;
		}

		$queue = $this->getPath($entry->task, '.queue');
		if ($entry->attempts >= $this->maxAttempts) {
			$this->moveFile("$queue/$entry->id", $this->getPath($entry->task, '.rejected') . "/$entry->id");
			return null;
		}

		$attempts = $entry->attempts + 1;
		$id = $this->formatName($entry->id, $attempts);
		$this->moveFile("$queue/$entry->id", "$queue/$id");
		return new Entry($entry->task, $id, $entry->pendingSince, $entry->payload, $attempts);
	}


	public function acknowledge(Entry $entry): void
	{
		if ($entry->payload !== null) {
			if (!@unlink($this->getPath($entry->task, '.queue') . '/' . $entry->id)) { // @ is escalated to exception
				throw new IOException("Unable to remove the payload $entry->id of $entry->task.");
			}
			return;
		}

		// a trigger that came during the run stays pending, but no longer since before the run
		$this->updateMarker($entry->task, fn(string $content) => str_ends_with($content, ' ' . $entry->id)
			? ''
			: $this->formatNow() . ' ' . explode(' ', $content)[1]);
	}


	/** @return list<string> */
	public function getPendingTasks(): array
	{
		$tasks = [];
		foreach (glob($this->dir . '/*.marker') ?: [] as $file) {
			if (filesize($file)) {
				$tasks[] = $this->getTaskName($file, '.marker');
			}
		}

		foreach (glob($this->dir . '/*.queue') ?: [] as $dir) {
			if (glob($dir . '/*.json')) {
				$tasks[] = $this->getTaskName($dir, '.queue');
			}
		}

		return array_values(array_unique($tasks));
	}


	/** @return array<string, int> */
	public function countRejected(): array
	{
		$res = [];
		foreach (glob($this->dir . '/*.rejected') ?: [] as $dir) {
			if ($count = count(glob($dir . '/*.json') ?: [])) {
				$res[$this->getTaskName($dir, '.rejected')] = $count;
			}
		}

		return $res;
	}


	public function retryRejected(string $task): int
	{
		$files = glob($this->getPath($task, '.rejected') . '/*.json') ?: [];
		foreach ($files as $file) {
			$this->moveFile($file, $this->getPath($task, '.queue') . '/' . $this->formatName(basename($file), 0));
		}

		return count($files);
	}


	private function createEntry(string $task, string $file, string $json): ?Entry
	{
		try {
			/** @var array{body?: string, headers?: array<lowercase-string, string>, query?: array<array-key, string>} $data */
			$data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
			$body = base64_decode($data['body'] ?? '', strict: true);
		} catch (\JsonException) {
			return null;
		}

		return $body !== false && preg_match('~^(.+)-\w+\.(\d+)\.json$~', basename($file), $m)
			? new Entry(
				$task,
				basename($file),
				$this->parseTime($m[1]),
				new Payload($body, $data['headers'] ?? [], $data['query'] ?? []),
				(int) $m[2],
			)
			: null;
	}


	private function formatName(string $name, int $attempts): string
	{
		return preg_replace('~\.\d+\.json$~', ".$attempts.json", $name);
	}


	private function readMarker(string $task): string
	{
		$file = $this->getPath($task, '.marker');
		if (!is_file($file)) {
			return '';
		}

		$handle = $this->openFile($file);
		try {
			if (!flock($handle, LOCK_SH)) {
				throw new IOException("Unable to lock the marker of $task.");
			}
			return (string) stream_get_contents($handle);
		} finally {
			fclose($handle);
		}
	}


	/**
	 * Rewrites the marker under its lock, so that a concurrent trigger is never overwritten.
	 * @param \Closure(string): string $update
	 */
	private function updateMarker(string $task, \Closure $update): void
	{
		$handle = $this->openFile($this->getPath($task, '.marker'));
		try {
			if (!flock($handle, LOCK_EX)) {
				throw new IOException("Unable to lock the marker of $task.");
			}

			$content = (string) stream_get_contents($handle);
			$new = $update($content);
			if ($new !== $content
				&& (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $new) !== strlen($new) || !fflush($handle))
			) {
				throw new IOException("Unable to write the marker of $task.");
			}
		} finally {
			fclose($handle);
		}
	}


	/** @return list<string> oldest first */
	private function getQueueFiles(string $task): array
	{
		return glob($this->getPath($task, '.queue') . '/*.json') ?: [];
	}


	/** Writes the file whole or not at all. */
	private function writeFile(string $file, string $content): void
	{
		if (@file_put_contents("$file.tmp", $content) === false || !@rename("$file.tmp", $file)) { // @ is escalated to exception
			throw new IOException("Unable to write $file.");
		}
	}


	private function moveFile(string $from, string $to): void
	{
		$this->createDir(dirname($to));
		if (!@rename($from, $to)) { // @ is escalated to exception
			throw new IOException("Unable to move $from to $to.");
		}
	}


	/** @return resource */
	private function openFile(string $file): mixed
	{
		$this->createDir(dirname($file));
		return @fopen($file, 'c+') ?: throw new IOException("Unable to open $file."); // @ is escalated to exception
	}


	private function createDir(string $dir): void
	{
		if (!is_dir($dir) && !@mkdir($dir, recursive: true) && !is_dir($dir)) { // @ is escalated to exception
			throw new IOException("Unable to create directory $dir.");
		}
	}


	private function getPath(string $task, string $suffix): string
	{
		return $this->dir . '/' . strtr($task, '\\', '.') . $suffix;
	}


	private function getTaskName(string $path, string $suffix): string
	{
		return strtr(basename($path, $suffix), '.', '\\');
	}


	private function formatNow(): string
	{
		return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(self::TimeFormat);
	}


	private function parseTime(string $time): \DateTimeImmutable
	{
		return \DateTimeImmutable::createFromFormat(self::TimeFormat, $time, new \DateTimeZone('UTC'))
			?: throw new IOException("Invalid time '$time' in the store.");
	}
}
