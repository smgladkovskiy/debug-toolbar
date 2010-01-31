<?php defined('SYSPATH') or die('No direct script access.');

class DebugToolbar
{
	public static $benchmark_name = 'debug_toolbar';

	/**
	 * Renders the Debug Toolbar
	 *
	 * @param bool print rendered output
	 * @return string debug toolbar rendered output
	 */
	public static function render($print = false)
	{
		$token = Profiler::start('custom', self::$benchmark_name);

		$template = new View('toolbar');

		// Database panel
		if (Kohana::config('debug_toolbar.panels.database') === TRUE)
		{
			$queries = self::get_queries();
			$template->set('queries', $queries['data'])->set('query_count', $queries['count']);
		}

		// Logs panel
		if (Kohana::config('debug_toolbar.panels.logs') === TRUE)
		{
			$template->set('logs', self::get_logs());
		}

		// Vars and Config panel
		if (Kohana::config('debug_toolbar.panels.vars_and_config') === TRUE)
		{
			$template->set('configs', self::get_configs());
		}

		// Files panel
		if (Kohana::config('debug_toolbar.panels.files') === TRUE)
		{
			$template->set('files', self::get_files());
		}

		// Modules panel
		if (Kohana::config('debug_toolbar.panels.modules') === TRUE)
		{
			$template->set('modules', self::get_modules());
		}

		// FirePHP
		if (Kohana::config('debug_toolbar.firephp_enabled') === TRUE)
		{
			self::firephp();
		}

		// Set alignment for toolbar
		switch (Kohana::config('debug_toolbar.align'))
		{
			case 'right':
			case 'center':
			case 'left':
				$template->set('align', Kohana::config('debug_toolbar.align'));
				break;
			default:
				$template->set('align', 'left');
		}

		// Javascript for toolbar
		$template->set('scripts', file_get_contents(Kohana::find_file('views', 'toolbar', 'js')));

		// CSS for toolbar
		$styles = file_get_contents(Kohana::find_file('views', 'toolbar', 'css'));

		Profiler::stop($token);

		// Benchmarks panel
		if (Kohana::config('debug_toolbar.panels.benchmarks') === TRUE)
		{
			$template->set('benchmarks', self::get_benchmarks());
		}

		if ($output = Request::instance()->response and self::is_enabled())
		{
			// Try to add css just before the </head> tag
			if (stripos($output, '</head>') !== FALSE)
			{
				$output = str_ireplace('</head>', $styles.'</head>', $output);
			}
			else
			{
				// No </head> tag found, append styles to output
				$template->set('styles', $styles);
			}

			// Try to add js and HTML just before the </body> tag
			if (stripos($output, '</body>') !== FALSE)
			{
				$output = str_ireplace('</body>', $template->render().'</body>', $output);
			}
			else
			{
				// Closing <body> tag not found, just append toolbar to output
				$output .= $template->render();
			}

			Request::instance()->response = $output;
		}
		else
		{
			$template->set('styles', $styles);

			return ($print ? $template->render() : $template);
		}
	}

	/**
	 * Retrieves query benchmarks from Database
	 */
	public static function get_queries()
	{
		$result = array();
		$count = 0;

		$groups = Profiler::groups();
		foreach(Database::$instances as $name => $db)
		{
			$group_name = 'database (' . strtolower($name) . ')';
			$group = arr::get($groups, $group_name, FALSE);

			if ($group)
			{
				foreach ($group as $query => $tokens)
				{
					foreach ($tokens as $token)
						$result[$name][] = array('name' => $query) + Profiler::total($token);
					$count += count($tokens);
				}
			}
		}
		return array('count' => $count, 'data' => $result);
	}

	/**
	 * Creates a formatted array of all Benchmarks
	 *
	 * @return array formatted benchmarks
	 */
	public static function get_benchmarks()
	{
		if (Kohana::$profiling == FALSE)
		{
			return array();
		}
		$groups = Profiler::groups();
		$result = array();
		foreach(array_keys($groups) as $group)
		{
			if (strpos($group, 'database (') === FALSE)
			{
				foreach($groups[$group] as $name => $marks)
				{
					$stats = Profiler::stats($marks);
					$result[$group][] = array
					(
						'name'			=> $name,
						'count'			=> count($marks),
						'total_time'	=> $stats['total']['time'],
						'avg_time'		=> $stats['average']['time'],
						'total_memory'	=> $stats['total']['memory'],
						'avg_memory'	=> $stats['average']['memory'],
					);
				}
			}
		}
		// add total stats
		$total = Profiler::application();
		$result['application'] = array
		(
			'count'			=> 1,
			'total_time'	=> $total['current']['time'],
			'avg_time'		=> $total['average']['time'],
			'total_memory'	=> $total['current']['memory'],
			'avg_memory'	=> $total['average']['memory'],

		);

		return $result;
	}

	/**
	 * Get list of included files
	 *
	 * @return array file currently included by php
	 */
	public static function get_files()
	{
		$files = (array)get_included_files();
		sort($files);
		return $files;
	}

	/**
	 *
	 * @return array  module_name => module_path
	 */
	public static function get_modules()
	{
		return Kohana::modules();
	}

	/**
	 * Add toolbar data to FirePHP console
	 * @TODO  change benchmark logic to KO3 style
	 */
	private static function firephp()
	{return;
		$firephp = FirePHP::getInstance(TRUE);
		$firephp->fb('KOHANA DEBUG TOOLBAR:');

		// Globals
		$globals = array(
			'Post'    => empty($_POST)    ? array() : $_POST,
			'Get'     => empty($_GET)     ? array() : $_GET,
			'Cookie'  => empty($_COOKIE)  ? array() : $_COOKIE,
			'Session' => empty($_SESSION) ? array() : $_SESSION
		);

		foreach ($globals as $name => $global)
		{
			$table = array();
			$table[] = array($name,'Value');

			foreach((array)$global as $key => $value)
			{
				if (is_object($value))
				{
					$value = get_class($value).' [object]';
				}

				$table[] = array($key, $value);
			}

			$message = "$name: ".count($global).' variables';

			$firephp->fb(array($message, $table), FirePHP::TABLE);
		}

		// Database
		$queries = self::get_queries();

		$total_time = $total_rows = 0;
		$table = array();
		$table[] = array('SQL Statement','Time','Rows');

		foreach ((array)$queries as $query)
		{
			$table[] = array(
				str_replace("\n",' ',$query['query']),
				number_format($query['time'], 3),
				$query['rows']
			);

			$total_time += $query['time'];
			$total_rows += $query['rows'];
		}

		$message = 'Queries: '.count($queries).' SQL queries took '.
			number_format($total_time, 3).' seconds and returned '.$total_rows.' rows';

		$firephp->fb(array($message, $table), FirePHP::TABLE);

		// Benchmarks
		$benchmarks = self::get_benchmarks();

		$table = array();
		$table[] = array('Benchmark', 'Time', 'Memory');

		foreach ((array)$benchmarks as $name => $benchmark)
		{
			$table[] = array(
				ucwords(str_replace(array('_', '-'), ' ', str_replace(SYSTEM_BENCHMARK.'_', '', $name))),
				number_format($benchmark['time'], 3). ' s',
				text::bytes($benchmark['memory'])
			);
		}

		$message = 'Benchmarks: '.count($benchmarks).' benchmarks took '.
			number_format($benchmark['time'], 3).' seconds and used up '.
			text::bytes($benchmark['memory']).' memory';

		$firephp->fb(array($message, $table), FirePHP::TABLE);
	}

	/**
	 * Determines if all the conditions are correct to display the toolbar
	 * (pretty kludgy, I know)
	 *
	 * @returns bool toolbar enabled
	 */
	public static function is_enabled()
	{
		// Don't auto render toolbar for ajax requests
		if (Request::$is_ajax)
			return FALSE;

		// Don't auto render toolbar if $_GET['debug'] = 'false'
		if (isset($_GET['debug']) and strtolower($_GET['debug']) == 'false')
			return FALSE;

		// Don't auto render if auto_render config is FALSE
		if (Kohana::config('debug_toolbar.auto_render') !== TRUE)
			return FALSE;

		// Auto render if secret key isset
		$secret_key = Kohana::config('debug_toolbar.secret_key');
		if ($secret_key !== FALSE and isset($_GET[$secret_key]))
			return TRUE;

		// Don't auto render when IN_PRODUCTION (this can obviously be
		// overridden by the above secret key)
		if (IN_PRODUCTION)
			return FALSE;

		return TRUE;
	}
}