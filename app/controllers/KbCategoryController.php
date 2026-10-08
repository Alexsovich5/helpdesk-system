<?php

class KbCategoryController extends BaseController {

	public function index()
	{
		$categories = KbCategory::orderBy('name')->get();
		$counts = DB::table('kb_articles')
			->select('kb_category_id', DB::raw('COUNT(*) AS total'), DB::raw('SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) AS published'))
			->groupBy('kb_category_id')
			->get();

		$totals = array();
		foreach ($counts as $row)
		{
			$totals[$row->kb_category_id] = array('total' => (int) $row->total, 'published' => (int) $row->published);
		}

		return View::make('kb.categories', array('categories' => $categories, 'totals' => $totals));
	}

	public function store()
	{
		$validator = Validator::make(Input::only('name'), array(
			'name' => 'required|max:100|unique:kb_categories,name',
		));

		if ($validator->fails())
		{
			return Redirect::to('kb/categories')->withErrors($validator)->withInput();
		}

		$category = KbCategory::create(array('name' => trim(Input::get('name'))));

		return Redirect::to('kb/categories')->with('status', "Category \"{$category->name}\" added.");
	}

}
