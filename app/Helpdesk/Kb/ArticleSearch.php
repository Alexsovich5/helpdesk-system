<?php namespace Helpdesk\Kb;

use KbArticle;
use User;

/**
 * Keyword search over knowledge-base articles. The query is split on
 * whitespace and every term has to appear in the title or the body;
 * matching ignores case. Requesters only ever get published articles.
 */
class ArticleSearch {

	/**
	 * @param  string  $query
	 * @param  User    $user
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function search($query, User $user)
	{
		$builder = KbArticle::query();

		if ( ! $user->isAgent()) $builder->published();

		foreach ($this->terms($query) as $term)
		{
			$like = '%'.$this->escape(mb_strtolower($term, 'UTF-8')).'%';

			$builder->where(function($q) use ($like)
			{
				$q->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", array($like))
					->orWhereRaw("LOWER(body) LIKE ? ESCAPE '!'", array($like));
			});
		}

		return $builder;
	}

	/**
	 * @param  string  $query
	 * @return array
	 */
	public function terms($query)
	{
		return preg_split('/\s+/u', trim((string) $query), -1, PREG_SPLIT_NO_EMPTY);
	}

	/**
	 * Makes % and _ in a term match literally.
	 */
	protected function escape($term)
	{
		return str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $term);
	}

}
