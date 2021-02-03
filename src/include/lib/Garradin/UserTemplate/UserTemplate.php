<?php

namespace Garradin\UserTemplate;

use KD2\Brindille;
use KD2\Brindille_Exception;
use KD2\ErrorManager;

use Garradin\Config;
use Garradin\DB;
use Garradin\Plugin;
use Garradin\Utils;

use Garradin\Files\Files;
use Garradin\Web\Skeleton;
use Garradin\Entities\Web\Page;
use Garradin\Web\Web;
use Garradin\Entities\Files\File;
use Garradin\UserTemplate\Modifiers;

use const Garradin\{WWW_URL, ADMIN_URL, CACHE_ROOT, DATA_ROOT};

class UserTemplate extends Brindille
{
	protected $path;
	protected $hash;
	protected $modified;
	protected $file;

	static protected $root_variables;

	static protected $_cache = [];

	static public function cache(string $id, callable $callback)
	{
		if (!array_key_exists($id, self::$_cache)) {
			self::$_cache[$id] = $callback();
		}

		return self::$_cache[$id];
	}

	static public function getRootVariables()
	{
		if (null !== self::$root_variables) {
			return self::$root_variables;
		}

		static $keys = ['adresse_asso', 'champ_identifiant', 'champ_identite', 'couleur1', 'couleur2', 'email_asso', 'monnaie', 'nom_asso', 'pays', 'site_asso', 'telephone_asso'];

		if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']))
		{
			if (function_exists('locale_accept_from_http'))
			{
			   $lang = locale_accept_from_http($_SERVER['HTTP_ACCEPT_LANGUAGE']);
			}
			else
			{
				$lang = preg_replace('/[^a-z]/i', '', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
				$lang = strtolower(substr($lang, 0, 2));
			}

			$lang = strtolower(substr($lang, 0, 2));
		}
		else
		{
			$lang = '';
		}

		$config = Config::getInstance();

		$config = array_intersect_key($config->asArray(), array_flip($keys));

		self::$root_variables = [
			'root_url'     => WWW_URL,
			'admin_url'    => ADMIN_URL,
			'_GET'         => &$_GET,
			'_POST'        => &$_POST,
			'visitor_lang' => $lang,
			'config'       => $config,
		];

		return self::$root_variables;
	}

	public function __construct(?File $file = null)
	{
		if ($file) {
			$this->file = $file;
			$this->hash = sha1(DATA_ROOT . $file->id);
			$this->modified = $file->modified->getTimestamp();
		}

		$this->assignArray(self::getRootVariables());

		$this->registerDefaults();

		Modifiers::registerAll($this);

		$params = [
			'template' => $this,
		];

		Plugin::fireSignal('usertemplate.init', $params);

		$this->registerSection('pages', [$this, 'sectionPages']);
		$this->registerSection('articles', [$this, 'sectionArticles']);
		$this->registerSection('categories', [$this, 'sectionCategories']);

		$this->registerSection('files', [$this, 'sectionFiles']);
		$this->registerSection('documents', [$this, 'sectionDocuments']);
		$this->registerSection('images', [$this, 'sectionImages']);

		$this->registerFunction('http', [$this, 'functionHTTP']);
		$this->registerFunction('include', [$this, 'functionInclude']);
		$this->registerFunction('dump', function (array $params, Brindille $tpl) {
			if (!count($params)) {
				$params = $tpl->getAllVariables();
			}

			$dump = htmlspecialchars(ErrorManager::dump($params));
			// FIXME: only send back HTML when content-type is text/html, or send raw text
			return sprintf('<pre style="background: yellow; padding: 5px; overflow: auto">%s</pre>', $dump);
		});

		$this->registerModifier('format_file_size', [Utils::class, 'format_bytes']);

		Plugin::fireSignal('usertemplate.init', ['template' => $this]);
	}

	public function setSource(string $path)
	{
		$this->path = $path;
		$this->hash = sha1($path);
		$this->modified = filemtime($path);
	}

	public function display(): void
	{
		$compiled_path = CACHE_ROOT . '/compiled/s_' . $this->hash . '.php';

		if (file_exists($compiled_path) && filemtime($compiled_path) >= $this->modified) {
			require $compiled_path;
			return;
		}

		$tmp_path = $compiled_path . '.tmp';

		$source = $this->file ? $this->file->fetch() : file_get_contents($this->path);

		try {
			$code = $this->compile($source);
			file_put_contents($tmp_path, $code);

			require $tmp_path;
		}
		catch (Brindille_Exception $e) {
			throw new Brindille_Exception(sprintf("Erreur de syntaxe dans '%s' : %s",
				$this->file ? $this->file->name : basename($this->path),
				$e->getMessage()), 0, $e);
		}
		catch (\Throwable $e) {
			// Don't delete temporary file as it can be used to debug
			throw $e;
		}

		if (!file_exists(dirname($compiled_path))) {
			Utils::safe_mkdir(dirname($compiled_path), 0777, true);
		}

		rename($tmp_path, $compiled_path);
	}

	public function fetch(): string
	{
		ob_start();
		$this->display();
		return ob_get_clean();
	}

	public function functionInclude(array $params, UserTemplate $ut, int $line): void
	{
		if (empty($params['file'])) {
			throw new Brindille_Exception(sprintf('Ligne %d: argument "file" manquant pour la fonction "include"', $line));
		}

		// Avoid recursive loops
		$from = $ut->get('included_from') ?? [];

		if (in_array($params['file'], $from)) {
			throw new Brindille_Exception(sprintf('Ligne %d : boucle infinie d\'inclusion détectée : %s', $line, $params['file']));
		}

		$s = new Skeleton($params['file']);

		if (!$s->exists()) {
			throw new Brindille_Exception(sprintf('Ligne %d : fonction "include" : le fichier à inclure "%s" n\'existe pas', $line, $params['file']));
		}

		$params['included_from'] = array_merge($from, [$params['file']]);

		$s->display($params);
	}

	public function functionHTTP(array $params): void
	{
		if (headers_sent()) {
			return;
		}

		if (isset($params['code'])) {
			static $codes = [
				100 => 'Continue',
				101 => 'Switching Protocols',
				102 => 'Processing',
				200 => 'OK',
				201 => 'Created',
				202 => 'Accepted',
				203 => 'Non-Authoritative Information',
				204 => 'No Content',
				205 => 'Reset Content',
				206 => 'Partial Content',
				207 => 'Multi-Status',
				300 => 'Multiple Choices',
				301 => 'Moved Permanently',
				302 => 'Found',
				303 => 'See Other',
				304 => 'Not Modified',
				305 => 'Use Proxy',
				306 => 'Switch Proxy',
				307 => 'Temporary Redirect',
				400 => 'Bad Request',
				401 => 'Unauthorized',
				402 => 'Payment Required',
				403 => 'Forbidden',
				404 => 'Not Found',
				405 => 'Method Not Allowed',
				406 => 'Not Acceptable',
				407 => 'Proxy Authentication Required',
				408 => 'Request Timeout',
				409 => 'Conflict',
				410 => 'Gone',
				411 => 'Length Required',
				412 => 'Precondition Failed',
				413 => 'Request Entity Too Large',
				414 => 'Request-URI Too Long',
				415 => 'Unsupported Media Type',
				416 => 'Requested Range Not Satisfiable',
				417 => 'Expectation Failed',
				418 => 'I\'m a teapot',
				422 => 'Unprocessable Entity',
				423 => 'Locked',
				424 => 'Failed Dependency',
				425 => 'Unordered Collection',
				426 => 'Upgrade Required',
				449 => 'Retry With',
				450 => 'Blocked by Windows Parental Controls',
				500 => 'Internal Server Error',
				501 => 'Not Implemented',
				502 => 'Bad Gateway',
				503 => 'Service Unavailable',
				504 => 'Gateway Timeout',
				505 => 'HTTP Version Not Supported',
				506 => 'Variant Also Negotiates',
				507 => 'Insufficient Storage',
				509 => 'Bandwidth Limit Exceeded',
				510 => 'Not Extended',
			];

			if (!isset($codes[$params['code']])) {
				throw new Brindille_Exception('Code HTTP inconnu');
			}

			header(sprintf('HTTP/1.1 %d %s', $params['code'], $codes[$params['code']]), true);
		}
		elseif (isset($params['redirect'])) {
			header('Location: ' . WWW_URL . $params['redirect'], true);
		}
		elseif (isset($params['type'])) {
			header('Content-Type: ' . $params['type'], true);
		}
		else {
			throw new Brindille_Exception('No valid parameter found for http function');
		}
	}

	public function sectionCategories(array $params, self $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['where'] .= ' AND w.type = ' . Page::TYPE_CATEGORY;
		return $this->sectionPages($params, $tpl, $line);
	}

	public function sectionArticles(array $params, self $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['where'] .= ' AND w.type = ' . Page::TYPE_PAGE;
		return $this->sectionPages($params, $tpl, $line);
	}

	public function sectionPages(array $params, self $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['select'] = 'w.*';
		$params['tables'] = 'web_pages w';

		if (isset($params['search'])) {
			$params['tables'] .= ' INNER JOIN files_search s USING (path)';
			$params['select'] .= ', rank(matchinfo(s), 0, 1.0, 1.0) AS points';
			$params['where'] .= ' AND s MATCH :search';

			if (!isset($params['order'])) {
				$params['order'] = 'points DESC';
			}

			$params[':search'] = $params['search'];
			unset($params['search']);
		}

		if (isset($params['uri'])) {
			$params['where'] .= ' AND w.uri = :uri';
			$params['limit'] = 1;
			$params[':uri'] = $params['uri'];
			unset($params['uri']);
		}

		if (isset($params['parent'])) {
			$params['where'] .= ' AND w.parent_id = :parent_id';
			$params[':parent_id'] = $params['parent'];
			unset($params['parent']);
		}

		if (isset($params['future'])) {
			if (!$params['future']) {
				$params['where'] .= ' AND w.created <= datetime()';
			}

			unset($params['future']);
		}

		foreach ($this->sectionSQL($params, $tpl, $line) as $row) {
			$data = $row;
			unset($data['points']);

			$page = new Page;
			$page->load($data);
			$page->exists(true);

			$row = array_merge($row, $page->asArray());
			$row['url'] = $page->url();
			$row['html'] = $page->render();

			yield $row;
		}
	}

	public function sectionImages(array $params, self $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['where'] .= ' AND f.image = 1';
		return $this->sectionFiles($params, $tpl, $line);
	}

	public function sectionDocuments(array $params, self $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['where'] .= ' AND f.image = 0';
		return $this->sectionFiles($params, $tpl, $line);
	}
	public function sectionFiles(array $params, self $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		if (empty($params['parent'])) {
			throw new Brindille_Exception('La section "files" doit obligatoirement comporter un paramètre "parent"');
		}

		$parent = (int) $params['parent'];

		// Fetch page
		$page = self::cache('page_' . $parent, function () use ($parent) {
			return Web::get($parent);
		});

		if (!$page) {
			return;
		}

		// Fetch files for this page
		$count = self::cache('page_files_' . $parent, function () use ($page) {
			$subpath = $page->subpath();
			return Files::listToSQL($subpath);
		});

		if (!$count) {
			return;
		}

		$params['select'] = 'f.*';
		$params['tables'] = 'files_tmp f';
		$params['where'] .= ' AND f.path = :path';
		$params[':path'] = $page->subpath();
		unset($params['parent']);

		// Generate a temporary table containing the list of files included in the text
		if (isset($params['except_in_text'])) {
			// Don't regenerate that table for each section called in the page,
			// we assume the content and list of files will not change between sections
			self::cache('page_files_text_' . $parent, function () use ($page) {
				$db = DB::getInstance();
				$db->begin();

				// Put files mentioned in the text in a temporary table
				$db->exec('CREATE TEMP TABLE IF NOT EXISTS files_tmp_in_text (page_id, name);');

				foreach (Page::findTaggedAttachments($page->raw()) as $name) {
					$db->insert('files_tmp_in_text', ['page_id' => $page->id(), 'name' => $name]);
				}

				$db->commit();
			});

			$params['where'] .= sprintf(' AND f.name NOT IN (SELECT name FROM files_tmp_in_text WHERE page_id = %d)', $page->id());
		}

		if (isset($params['order']) && $params['order'] == 'name') {
			$params['order'] .= ' COLLATE NOCASE';
		}

		foreach ($this->sectionSQL($params, $tpl, $line) as $row) {
			yield $row;
		}
	}

	public function sectionSQL(array $params, self $tpl, int $line): \Generator
	{
		static $defaults = [
			'select' => '*',
			'order' => '1',
			'begin' => 0,
			'limit' => 1000,
			'where' => '',
		];

		if (!isset($params['tables'])) {
			throw new Brindille_Exception('Missing parameter "tables"');
		}

		foreach ($defaults as $key => $default_value) {
			if (!isset($params[$key])) {
				$params[$key] = $default_value;
			}
		}

		// Allow for count=true, count=1 and also count="DISTINCT user_id" count="id"
		if (isset($params['count'])) {
			$params['select'] = sprintf('COUNT(%s) AS count', $params['count'] == 1 ? '*' : $params['count']);
		}

		$sql = sprintf('SELECT %s FROM %s WHERE 1 %s %s ORDER BY %s LIMIT %d,%d;',
			$params['select'],
			$params['tables'],
			$params['where'] ?? '',
			isset($params['group']) ? 'GROUP BY ' . $params['group'] : '',
			$params['order'],
			$params['begin'],
			$params['limit']
		);

		try {
			$db = DB::getInstance();
			$statement = $db->protectSelect(null, $sql);

			$args = [];

			foreach ($params as $key => $value) {
				if (substr($key, 0, 1) == ':') {
					$args[$key] = $value;
				}
			}

			foreach ($args as $key => $value) {
				$statement->bindValue($key, $value, $db->getArgType($value));
			}

			if (!empty($params['debug'])) {
				echo sprintf('<pre style="padding: 5px; background: yellow;">%s</pre>', htmlspecialchars($statement->getSQL(true)));
			}

			unset($params, $sql);

			$result = $statement->execute();
		}
		catch (\Exception $e) {
			throw new Brindille_Exception(sprintf("Erreur SQL à la ligne %d : %s\nRequête exécutée : %s", $line, $db->lastErrorMsg(), $sql));
		}

		while ($row = $result->fetchArray(\SQLITE3_ASSOC))
		{
			yield $row;
		}
	}
}
