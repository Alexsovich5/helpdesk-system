<?php

class AssetController extends BaseController {

	const PER_PAGE = 25;

	/**
	 * GET /assets?q=&status=&page=
	 *
	 * "q" matches the tag, name, serial or location.
	 */
	public function index()
	{
		$filters = array_map('trim', array_merge(
			array('q' => '', 'status' => ''),
			array_filter(Input::only('q', 'status'), 'is_string')
		));

		$query = Asset::with('assignedUser');

		if (in_array($filters['status'], Asset::$statuses, true))
		{
			$query->where('status', $filters['status']);
		}

		if ($filters['q'] !== '')
		{
			$term = '%'.$filters['q'].'%';

			$query->where(function($q) use ($term)
			{
				$q->where('asset_tag', 'like', $term)
					->orWhere('name', 'like', $term)
					->orWhere('serial', 'like', $term)
					->orWhere('location', 'like', $term);
			});
		}

		$assets = $query->orderBy('asset_tag')->paginate(static::PER_PAGE);
		$assets->appends(array_filter($filters, 'strlen'));

		return View::make('assets.index', array(
			'assets'   => $assets,
			'filters'  => $filters,
			'statuses' => Asset::$statuses,
			'user'     => Auth::user(),
		));
	}

	public function show($id)
	{
		$asset = Asset::find($id);
		if (is_null($asset)) return $this->notFound();
		$asset->load('assignedUser', 'tickets.requester', 'tickets.assignee');

		return View::make('assets.show', array('asset' => $asset, 'user' => Auth::user()));
	}

	public function create()
	{
		return $this->form(new Asset(array('status' => 'in_stock')));
	}

	public function store()
	{
		$validator = $this->validator();

		if ($validator->fails())
		{
			return Redirect::to('assets/create')->withErrors($validator)->withInput();
		}

		$asset = new Asset;
		$this->fill($asset);
		$asset->save();

		return Redirect::to('assets/'.$asset->id)->with('status', "Asset {$asset->asset_tag} added.");
	}

	public function edit($id)
	{
		$asset = Asset::find($id);
		if (is_null($asset)) return $this->notFound();

		return $this->form($asset);
	}

	public function update($id)
	{
		$asset = Asset::find($id);
		if (is_null($asset)) return $this->notFound();
		$validator = $this->validator($asset);

		if ($validator->fails())
		{
			return Redirect::to('assets/'.$asset->id.'/edit')->withErrors($validator)->withInput();
		}

		$this->fill($asset);
		$asset->save();

		return Redirect::to('assets/'.$asset->id)->with('status', "Asset {$asset->asset_tag} saved.");
	}

	/**
	 * Assets that tickets refer to are kept for their history; retire
	 * them instead.
	 */
	public function destroy($id)
	{
		$asset = Asset::find($id);
		if (is_null($asset)) return $this->notFound();

		if ($asset->tickets()->exists())
		{
			return Redirect::to('assets/'.$asset->id)
				->withErrors(array('asset' => 'Tickets refer to this asset, so it cannot be deleted. Set its status to retired instead.'));
		}

		$asset->delete();

		return Redirect::to('assets')->with('status', "Asset {$asset->asset_tag} deleted.");
	}

	protected function form(Asset $asset)
	{
		return View::make('assets.form', array(
			'asset'    => $asset,
			'statuses' => Asset::$statuses,
			'users'    => array('' => 'Unassigned') + User::orderBy('name')->lists('name', 'id'),
		));
	}

	protected function notFound()
	{
		return Response::make('Not Found', 404);
	}

	protected function validator(Asset $asset = null)
	{
		$unique = 'unique:assets,asset_tag'.($asset ? ','.$asset->id : '');
		$max = Asset::$maxLengths;

		return Validator::make(Input::only('asset_tag', 'name', 'type', 'serial', 'location', 'status', 'assigned_user_id'), array(
			'asset_tag'        => 'required|max:'.$max['asset_tag'].'|'.$unique,
			'name'             => 'required|max:'.$max['name'],
			'type'             => 'required|max:'.$max['type'],
			'serial'           => 'max:'.$max['serial'],
			'location'         => 'max:'.$max['location'],
			'status'           => 'required|in:'.implode(',', Asset::$statuses),
			'assigned_user_id' => 'exists:users,id',
		), array(
			'assigned_user_id.exists' => 'Choose a user from the list.',
		));
	}

	protected function fill(Asset $asset)
	{
		foreach (array('asset_tag', 'name', 'type', 'status') as $column)
		{
			$asset->$column = trim(Input::get($column));
		}

		foreach (array('serial', 'location') as $column)
		{
			$value = trim((string) Input::get($column));
			$asset->$column = $value === '' ? null : $value;
		}

		$assignee = (string) Input::get('assigned_user_id');
		$asset->assigned_user_id = ctype_digit($assignee) ? (int) $assignee : null;
	}

}
