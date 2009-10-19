<?
class git {
	/** Query counter */
	static public $query_count = 0;
	
	/** Execute a git command */
	static function exec($cmd, $input=null, $gitdir=null, $worktree=null, $allow_guess=false) {
	static function exec($cmd, $input=null, $gitdir=null, $worktree=null, $allow_guess=false, $ignore_git_errors=false) {
		# build cmd
		if ($gitdir === null && !$allow_guess)
			$gitdir = gb::$site_dir.'/.git';
		if ($worktree === null && !$allow_guess)
			$worktree = gb::$site_dir;
		$cmd_prefix = 'git';
		if ($gitdir)
			$cmd_prefix .= ' --git-dir='.escapeshellarg($gitdir);
		if ($worktree)
			$cmd_prefix .= ' --work-tree='.escapeshellarg($worktree);
		$cmd = $cmd_prefix.' '.$cmd;
		
		#var_dump($cmd);
		gb::log(LOG_DEBUG, 'exec$ '.$cmd);
		$r = gb::shell($cmd, $input, gb::$site_dir);
		git::$query_count++;
		# fail?
		if ($r === null)
			return null;
		# check for errors
		if ($r[0] != 0) {
			$msg = trim($r[1]."\n".$r[2]);
			if ($ignore_git_errors && strpos($msg,'sh: ') !== 0)
				return $msg;
			if (strpos($r[2], 'Not a git repository') !== false)
				throw new GitUninitializedRepoError($msg, $r[0], $cmd);
			else
				throw new GitError($msg, $r[0], $cmd);
		}
		return $r[1];
	}
	
	static function escargs($args) {
		if ($args === null)
			return '';
		return is_string($args) ? escapeshellarg($args) : implode(' ', array_map('escapeshellarg', $args));
	}
	
# -----------------------------------------------------------------------------------------------
	
	static function config($key=null, $value=null, $guess_repo=true) {
		if ($value !== null) {
			$cmd = 'config --replace-all ';
			if ($value === true || $value === false) {
				$cmd .= ' --bool ';
				$value = $value ? 'true' : 'false';
			}
			elseif (is_int($value)) {
				$cmd .= ' --int ';
			}
			return trim(self::exec($cmd.escapeshellarg($key).' '.escapeshellarg($value),
				null, null, null, $guess_repo));
		}
		else {
			try {
				return trim(self::exec('config --get '.escapeshellarg($key),
					null, null, null, $guess_repo));
			}
			catch (GitError $e) {
				if (trim($e->getMessage()) === '')
					return null;
				throw $e;
			}
		}
	}
	
	static function init($gitdir=null, $worktree=null, $shared='true') {
		$mkdirmode = $shared === 'all' ? 0777 : 0775;
		
		if ($shared)
			$shared = '--shared='.$shared;
		if ($worktree === null)
			$worktree = gb::$site_dir;
		if ($gitdir === null)
			$gitdir = $worktree.'/.git';
		
		# create directories and chmod
		if (!is_dir($gitdir)) {
			mkdir($gitdir, $mkdirmode, true);
			@chmod($gitdir, $mkdirmode);
		}
		if (!is_dir($worktree)) {
			mkdir($worktree, $mkdirmode, true);
			@chmod($worktree, $mkdirmode);
		}
		
		# git init
		git::exec('init --quiet '.$shared, null, $gitdir, $worktree);
	}
	
	static function add($pathspec, $forceIncludeIgnored=true) {
		git::exec(($forceIncludeIgnored ? 'add --force ' : 'add ').escapeshellarg($pathspec));
		return $pathspec;
	}
	
	static function reset($pathspec=null, $commitobj=null, $flags='-q') {
		if ($pathspec) {
			if (is_array($pathspec))
				$pathspec = implode(' ', array_map('escapeshellarg',$pathspec));
			else
				$pathspec = escapeshellarg($pathspec);
			$pathspec = ' '.$pathspec;
		}
		$commitargs = '';
		if ($commitobj) {
			$badtype = false;
			if (!is_array($commitobj))
				$commitobj = array($commitobj);
			foreach ($commitobj as $c) {
				if (is_object($c)) {
					if (strtolower(get_class($c)) !== 'GitCommit')
						$badtype = true;
					else
						$commitargs .= ' '.escapeshellarg($c->id);
				}
				elseif (is_string($c))
					$commitargs .= escapeshellarg($c);
				else
					$badtype = true;
				if ($badtype)
					throw new InvalidArgumentException('$commitobj argument must be a string, a GitCommit '
						.'object or an array of any of the two mentioned types');
			}
		}
		git::exec('reset '.$flags.' '.$commitargs.' --'.$pathspec);
	}
	
	static function commit($message, $author=null, $pathspec=null, $deferred=false) {
		if ($deferred && gb::defer(array('gb', 'commit'), $message, $author, $pathspec, false)) {
			$pathspec = $pathspec ? r($pathspec) : '';
			gb::log('deferred commit -m %s --author %s %s',
				escapeshellarg($message), escapeshellarg($author), $pathspec);
			if (!$pathspec) {
				gb::log(LOG_WARNING,
					'deferred commits without pathspec might cause unexpected changesets');
			}
			return true;
		}
		
		if ($pathspec) {
			if (is_array($pathspec))
				$pathspec = implode(' ', array_map('escapeshellarg',$pathspec));
			else
				$pathspec = escapeshellarg($pathspec);
			$pathspec = ' '.$pathspec;
		}
		else {
			$pathspec = '';
		}
		$author = $author ? '--author='.escapeshellarg($author) : '';
		git::exec('commit -m '.escapeshellarg($message).' --quiet '.$author.' -- '.$pathspec);
		@chmod(gb::$site_dir.'/.git/COMMIT_EDITMSG', 0664);
		return true;
	}
	
	/** Each argument can be a string or and array of strings (or null to skip) */
	static function diff($commits=null, $paths=null, $args=null) {
		$commits = self::escargs($commits);
		$args = self::escargs($args);
		$paths = self::escargs($paths);
		$cmd = 'diff '.$args.' '.$commits;
		if ($paths)
			$cmd .= ' -- '.$paths;
		return git::exec($cmd);
	}
	
	static function cat_file($ids) {
		$id_to_data = array();
		if (is_string($ids)) {
			$ids = array($ids);
			$id_to_data = false;
		}
		
		# load
		$out = git::exec("cat-file --batch", implode("\n", $ids));
		$p = 0;
		$numobjects = count($ids);
		
		# parse
		for ($i=0; $i<$numobjects; $i++) {
			# <id> SP <type> SP <size> LF
			# <contents> LF
			$hend = strpos($out, "\n", $p);
			$hs = substr($out, $p, $hend-$p);
			$h = explode(' ', $hs);
			
			if ($h[1] === 'missing')
				throw new UnexpectedValueException('missing blob '.$hs);
			
			$dstart = $hend + 1;
			$size = intval($h[2]);
			$data = substr($out, $dstart, $size);
			if ($id_to_data === false) {
				# a single object was requested (string input)
				return $data;
			}
			$id_to_data[$h[0]] = $data;
			
			$p = $dstart + $size + 1;
		}
		
		return $id_to_data;
	}
	
	static function ls_staged($paths=null) {
		$paths = self::escargs($paths);
		$ls = rtrim(self::exec('ls-files --stage -t -z -- '.$paths));
		$files = array();
		if ($ls) {
			# Iterate objects
			$ls = explode("\0", $ls);
			foreach ($ls as $line) {
				# <status char> SP <mode> SP <object> SP <stage no> TAB <name>
				if (!$line)
					continue;
				$file = (object)array('status'=>null, 'id'=>null, 'name'=>null);
				$line = explode(' ', $line, 4);
				$file->status = $line[0];
				$file->id = $line[2];
				$file->name = gb_normalize_git_name(substr($line[3], strpos($line[3], "\t")+1));
				$files[] = $file;
			}
		}
		return $files;
	}
	
	static function ls_basic($args=null, $paths=null) {
		$args = self::escargs($args);
		$paths = self::escargs($paths);
		$ls = rtrim(self::exec('ls-files -z '.$args.' -- '.$paths));
		$files = array();
		if ($ls) {
			# Iterate objects
			$ls = explode("\0", $ls);
			foreach ($ls as $line) {
				if (!$line)
					continue;
				$files[] = gb_normalize_git_name($line);
			}
		}
		return $files;
	}
	
	static function ls_untracked($paths=null, $exclude=null) {
		$args = array('--other');
		if ($exclude) {
			$args[] = '--exclude';
			$args[] = $exclude;
		}
		return self::ls_basic($args, $paths);
	}
	
	static function ls_modified($paths=null, $exclude=null) {
		$args = array('--modified');
		if ($exclude) {
			$args[] = '--exclude';
			$args[] = $exclude;
		}
		return self::ls_basic($args, $paths);
	}
	
	static function ls_removed($paths=null, $exclude=null) {
		$args = array('--deleted');
		if ($exclude) {
			$args[] = '--exclude';
			$args[] = $exclude;
		}
		return self::ls_basic($args, $paths);
	}
	
	/** Retrieve ids for $pathspec (string or array of strings) at the current branch head */
	static function id_for_pathspec($pathspec) {
		if (is_string($pathspec))
			return trim(git::exec('rev-parse '.escapeshellarg(':'.$pathspec)));
		if (!is_array($pathspec))
			throw new InvalidArgumentException('$pathspec is '.gettype($pathspec));
		# array
		$pathspec_to_id = array();
		foreach ($pathspec as $k => $s)
			$pathspec[$k] = escapeshellarg(':'.$s);
		$lines = explode("\n", trim(git::exec('rev-parse '.implode(' ', $pathspec))));
		$i = 0;
		foreach ($pathspec as $s)
			$pathspec_to_id[$s] = $lines[$i++];
		return $pathspec_to_id;
	}
}
?>