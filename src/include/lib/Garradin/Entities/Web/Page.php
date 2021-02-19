<?php

namespace Garradin\Entities\Web;

use Garradin\DB;
use Garradin\Entity;
use Garradin\UserException;
use Garradin\Utils;
use Garradin\Entities\Files\File;
use Garradin\Files\Files;
use Garradin\Web\Render\Skriv;

use KD2\DB\EntityManager as EM;

use const Garradin\WWW_URL;

class Page extends Entity
{
	const TABLE = 'web_pages';

	protected $id;
	protected $parent_id;
	protected $path;
	protected $uri;
	protected $type;
	protected $status;
	protected $format;
	protected $title;
	protected $published;
	protected $modified;
	protected $content;

	protected $_types = [
		'id'        => 'int',
		'parent_id' => '?int',
		'path'      => 'string',
		'uri'       => 'string',
		'type'      => 'int',
		'status'    => 'string',
		'format'    => 'string',
		'title'     => 'string',
		'published' => 'DateTime',
		'modified'  => 'DateTime',
		'content'   => 'string',
	];

	const FORMAT_SKRIV = 'skriv';
	const FORMAT_ENCRYPTED = 'skriv/encrypted';

	const FORMATS_LIST = [
		self::FORMAT_SKRIV => 'SkrivML',
		self::FORMAT_ENCRYPTED => 'Chiffré',
	];

	const STATUS_ONLINE = 'online';
	const STATUS_DRAFT = 'draft';

	const STATUS_LIST = [
		self::STATUS_ONLINE => 'En ligne',
		self::STATUS_DRAFT => 'Brouillon',
	];

	const TYPE_CATEGORY = 1;
	const TYPE_PAGE = 2;

	protected $_file;
	protected $_attachments;
	protected $_content_modified = false;

	static public function create(int $type, ?int $parent_id, string $title, int $status = self::STATUS_ONLINE): self
	{
		$page = new self;
		$data = compact('type', 'parent_id', 'title', 'status');
		$data['uri'] = $title;
		$data['content'] = '';

		$page->importForm($data);

		return $page;
	}

	public function url(): string
	{
		return WWW_URL . $this->path;
	}

	public function raw(): string
	{
		return $this->content;
	}

	public function render(array $options = []): string
	{
		if ($this->format == self::FORMAT_SKRIV) {
			return \Garradin\Web\Render\Skriv::render($this, null, $options);
		}
		else if ($this->format == self::FORMAT_ENCRYPTED) {
			return \Garradin\Web\Render\EncryptedSkriv::render($this, null, $options);
		}
	}

	public function preview(string $content): string
	{
		return Skriv::render($this->file(), $content, ['prefix' => '#']);
	}

	public function save(): bool
	{
		$file = $this->file();

		if (isset($this->_modified['uri'])) {
			$path = dirname($this->path);

			if ($path) {
				$path .= '/';
			}

			$path .= $this->uri;

			$this->set('path', $path);
		}

		$exists = $this->exists();

		parent::save();

		if (isset($this->_modified['path'])) {
			$realpath = File::CONTEXT_WEB . '/' . $this->get('path') . '/index.txt';

			if ($exists) {
				$file->rename($realpath);
			}
			else {
				$this->_file = File::createAndStore(dirname($realpath), basename($realpath), null, $this->content);
			}
		}

		if (isset($this->_content_modified) && $exists) {
			$file->setContent((string)$this->content);
		}

		return true;
	}

	public function delete(): bool
	{
		$this->file()->delete();
		return parent::delete();
	}

	public function selfCheck(): void
	{
		$this->assert($this->type === self::TYPE_CATEGORY || $this->type === self::TYPE_PAGE, 'Unknown page type');
		$this->assert(array_key_exists($this->status, self::STATUS_LIST), 'Unknown page status');
		$this->assert(array_key_exists($this->format, self::FORMATS_LIST), 'Unknown page format');
		$this->assert(trim($this->title) !== '', 'Le titre ne peut rester vide');
		$this->assert(trim($this->uri) !== '', 'L\'URI ne peut rester vide');
		$this->assert((bool) $this->file(), 'Fichier manquant');

		$db = DB::getInstance();
		$where = $this->exists() ? sprintf(' AND id != %d', $this->id()) : '';
		$this->assert(!$db->test(self::TABLE, 'path = ?' . $where, $this->path), 'Cette adresse URI est déjà utilisée par une autre page, merci d\'en choisir une autre');
	}

	public function importForm(array $source = null)
	{
		if (null === $source) {
			$source = $_POST;
		}

		if (isset($source['parent_id']) && is_array($source['parent_id'])) {
			$source['parent_id'] = key($source['parent_id']);
		}

		if (isset($source['date']) && isset($source['date_time'])) {
			$source['created'] = $source['date'] . ' ' . $source['date_time'];
		}

		if (isset($source['uri'])) {
			$source['uri'] = Utils::transformTitleToURI($source['uri']);
		}

		if (!empty($source['encrypted']) ) {
			$this->_content_type = File::FILE_EXT_ENCRYPTED;
		}
		else {
			$this->_content_type = File::FILE_EXT_SKRIV;
		}

		if (isset($source['content']) && $source['content'] != $this->_content) {
			$this->_content = $source['content'];
			$this->_content_modified = true;
		}

		return parent::importForm($source);
	}

	public function getBreadcrumbs()
	{
		$sql = '
			WITH RECURSIVE parents(id, name, parent_id, level) AS (
				SELECT id, title, parent_id, 1 FROM web_pages WHERE id = ?
				UNION ALL
				SELECT p.id, p.title, p.parent_id, level + 1
				FROM web_pages p
					JOIN parents ON p.id = parents.parent_id
			)
			SELECT id, name FROM parents ORDER BY level DESC;';
		return DB::getInstance()->getAssoc($sql, $this->id());
	}

	public function listAttachments(): array
	{
		if (null === $this->_attachments) {
			$this->_attachments = $this->file()->listSubFiles();
		}

		return $this->_attachments;
	}

	static public function findTaggedAttachments(string $text): array
	{
		preg_match_all('/<<?(?:fichier|image)\s*(?:\|\s*)?([\w\d_.-]+)/ui', $text, $match, PREG_PATTERN_ORDER);
		preg_match_all('/(?:fichier|image):\/\/([\w\d_.-]+)/ui', $text, $match2, PREG_PATTERN_ORDER);

		return array_merge($match[1], $match2[1]);
	}

	/**
	 * Return list of images
	 * If $all is FALSE then this will only return images that are not present in the content
	 */
	public function getImageGallery(bool $all = true): array
	{
		return $this->getAttachmentsGallery($all, true);
	}

	/**
	 * Return list of files
	 * If $all is FALSE then this will only return files that are not present in the content
	 */
	public function getAttachmentsGallery(bool $all = true, bool $images = false): array
	{
		$out = [];

		if (!$all) {
			$tagged = $this->findTaggedAttachments($this->raw());
		}

		foreach ($this->listAttachments() as $a) {
			if ($images && !$a->image) {
				continue;
			}
			elseif (!$images && $a->image) {
				continue;
			}

			// Skip
			if (!$all && in_array($a->id, $tagged)) {
				continue;
			}

			$out[] = $a;
		}

		return $out;
	}

	public function export(): string
	{
		$meta = [
			'Title' => str_replace("\n", '', trim($this->title)),
			'Status' => $this->status,
			'Published' => $this->published->format('Y-m-d H:i:s'),
			'Format' => $this->format,
		];

		$out = '';

		foreach ($metas as $key => $value) {
			$out .= sprintf("%s: %s\n", $key, $value);
		}

		$out .= "\n----\n\n" . $this->raw();

		return $out;
	}

	static public function fromFile(File $file, array $files, string $str, ?int $parent_id = null): self
	{
		$page = new self;

		// Path is relative to web root
		$page->path = substr(dirname($file->path()), strlen(File::CONTEXT_WEB . '/'));

		$str = preg_replace("/\r\n|\r|\n/", "\n", $str);
		$str = explode("\n\n----\n\n", $str, 2);

		if (count($str) !== 2) {
			// FIXME: handle this case with more subtlety
			throw new \LogicException('Invalid page');
		}

		list($meta, $content) = $str;

		$meta = explode("\n", trim($meta));

		foreach ($meta as $line) {
			$key = strtolower(trim(strtok($line, ':')));
			$value = trim(strtok(''));

			if ($key == 'title') {
				$page->title = $value;
			}
			elseif ($key == 'published') {
				$page->published = new \DateTime($value);
			}
			elseif ($key == 'format') {
				$value = strtolower($value);

				if (!array_key_exists($value, self::FORMATS_LIST)) {
					throw new \LogicException('Unknown format: ' . $value);
				}

				$page->format = $value;
			}
			elseif ($key == 'status') {
				$value = strtolower($value);

				if (!array_key_exists($value, self::STATUS_LIST)) {
					throw new \LogicException('Unknown status: ' . $value);
				}

				$page->status = $value;
			}
			else {
				// Ignore other metadata
			}
		}

		$page->content = trim($content, "\n\r");
		$page->type = self::TYPE_PAGE;
		$page->modified = $file->modified;

		foreach ($files as $subfile) {
			if ($subfile->type == File::TYPE_DIRECTORY) {
				$page->type = self::TYPE_CATEGORY;
			}
		}

		return $page;
	}
}
