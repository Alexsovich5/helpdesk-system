<?php

use Helpdesk\Kb\ArticleSearch;

class KbArticleController extends BaseController {

	const PER_PAGE = 20;

	protected $search;

	public function __construct(ArticleSearch $search)
	{
		$this->search = $search;
	}

	/**
	 * GET /kb?q=&page=
	 */
	public function index()
	{
		$user = Auth::user();
		$q = trim((string) (is_string(Input::get('q')) ? Input::get('q') : ''));

		$articles = $this->search->search($q, $user)
			->with('category')
			->orderBy('title')
			->paginate(static::PER_PAGE);

		if ($q !== '') $articles->appends(array('q' => $q));

		return View::make('kb.index', array(
			'articles' => $articles,
			'q'        => $q,
			'user'     => $user,
		));
	}

	public function show($id)
	{
		$article = $this->findVisible($id);
		if (is_null($article)) return $this->notFound();

		$article->load('category', 'author');

		return View::make('kb.show', array('article' => $article, 'user' => Auth::user()));
	}

	public function create()
	{
		return View::make('kb.form', array(
			'article'    => new KbArticle,
			'categories' => $this->categoryOptions(),
		));
	}

	public function store()
	{
		$validator = $this->validator();

		if ($validator->fails())
		{
			return Redirect::to('kb/create')->withErrors($validator)->withInput();
		}

		$article = new KbArticle;
		$article->author_id = Auth::user()->id;
		$this->fill($article);
		$article->save();

		return Redirect::to('kb/'.$article->id)->with('status', $article->is_published ? 'Article published.' : 'Draft saved.');
	}

	public function edit($id)
	{
		$article = $this->findVisible($id);
		if (is_null($article)) return $this->notFound();

		return View::make('kb.form', array(
			'article'    => $article,
			'categories' => $this->categoryOptions(),
		));
	}

	public function update($id)
	{
		$article = $this->findVisible($id);
		if (is_null($article)) return $this->notFound();

		$validator = $this->validator();

		if ($validator->fails())
		{
			return Redirect::to('kb/'.$article->id.'/edit')->withErrors($validator)->withInput();
		}

		$this->fill($article);
		$article->save();

		return Redirect::to('kb/'.$article->id)->with('status', $article->is_published ? 'Article saved and published.' : 'Draft saved.');
	}

	/**
	 * Drafts answer 404 for anyone who is not an agent, the same as a
	 * missing article, so their titles do not leak.
	 *
	 * @return KbArticle|null
	 */
	protected function findVisible($id)
	{
		$article = KbArticle::find($id);

		if (is_null($article) || ! $article->isVisibleTo(Auth::user())) return null;

		return $article;
	}

	protected function notFound()
	{
		return Response::make('Not Found', 404);
	}

	protected function validator()
	{
		return Validator::make(Input::only('title', 'body', 'kb_category_id'), array(
			'title'          => 'required|max:255',
			'body'           => 'required',
			'kb_category_id' => 'required|exists:kb_categories,id',
		), array(
			'kb_category_id.required' => 'Choose a category.',
		));
	}

	protected function fill(KbArticle $article)
	{
		$article->title = Input::get('title');
		$article->body = Input::get('body');
		$article->kb_category_id = (int) Input::get('kb_category_id');
		$article->is_published = Input::get('is_published') === '1';
	}

	protected function categoryOptions()
	{
		return KbCategory::orderBy('name')->lists('name', 'id');
	}

}
