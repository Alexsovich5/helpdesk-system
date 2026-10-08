<?php

use Helpdesk\Assets\AssetCsvImporter;

class AssetCsvImporterTest extends TestCase {

	const HEADER = 'asset_tag,name,type,serial,location,status,assigned_username';

	protected $importer;

	protected $files = array();

	protected $carol;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		$this->carol = new User;
		$this->carol->username = 'carol';
		$this->carol->name = 'Carol';
		$this->carol->email = 'carol@helpdesk.local';
		$this->carol->role = 'requester';
		$this->carol->source = 'local';
		$this->carol->save();

		$this->importer = new AssetCsvImporter;
	}

	public function tearDown()
	{
		foreach ($this->files as $file) @unlink($file);

		parent::tearDown();
	}

	protected function csv(array $lines)
	{
		$path = tempnam(sys_get_temp_dir(), 'assets');
		file_put_contents($path, implode("\n", $lines)."\n");
		$this->files[] = $path;

		return $path;
	}

	protected function existing($tag, $status = 'in_use')
	{
		$asset = new Asset;
		$asset->asset_tag = $tag;
		$asset->name = 'Old name';
		$asset->type = 'laptop';
		$asset->status = $status;
		$asset->assigned_user_id = $this->carol->id;
		$asset->save();

		return $asset;
	}

	public function testCreatesNewAssets()
	{
		$result = $this->importer->import($this->csv(array(
			self::HEADER,
			'LT-0101,ThinkPad T440,laptop,PC0ABC12,HQ 2nd floor,in_use,carol',
			'PR-0007,HP LaserJet 400,printer,,,in_stock,',
		)));

		$this->assertSame(2, $result['created']);
		$this->assertSame(0, $result['updated']);
		$this->assertSame(0, $result['skipped']);

		$laptop = Asset::where('asset_tag', 'LT-0101')->first();
		$this->assertSame('ThinkPad T440', $laptop->name);
		$this->assertSame('laptop', $laptop->type);
		$this->assertSame('PC0ABC12', $laptop->serial);
		$this->assertSame('HQ 2nd floor', $laptop->location);
		$this->assertSame('in_use', $laptop->status);
		$this->assertEquals($this->carol->id, $laptop->assigned_user_id);

		$printer = Asset::where('asset_tag', 'PR-0007')->first();
		$this->assertNull($printer->serial);
		$this->assertNull($printer->location);
		$this->assertNull($printer->assigned_user_id);
	}

	public function testUpdatesExistingAssetsMatchedByTag()
	{
		$asset = $this->existing('LT-0042');

		$result = $this->importer->import($this->csv(array(
			self::HEADER,
			'LT-0042,Latitude E7440,laptop,5XK2Z12,Repair bench,repair,',
		)));

		$this->assertSame(0, $result['created']);
		$this->assertSame(1, $result['updated']);
		$this->assertSame(1, Asset::where('asset_tag', 'LT-0042')->count());

		$asset = Asset::find($asset->id);
		$this->assertSame('Latitude E7440', $asset->name);
		$this->assertSame('repair', $asset->status);
		$this->assertSame('Repair bench', $asset->location);
		$this->assertNull($asset->assigned_user_id);
	}

	public function testSkipsARowWithAnUnknownStatusAndReportsItsLineNumber()
	{
		$result = $this->importer->import($this->csv(array(
			self::HEADER,
			'LT-0101,ThinkPad T440,laptop,PC0ABC12,HQ 2nd floor,in_use,',
			'MN-0300,Dell U2414H,monitor,,HQ 3rd floor,lost,',
		)));

		$this->assertSame(1, $result['created']);
		$this->assertSame(1, $result['skipped']);
		$this->assertSame(array(3), array_keys($result['errors']));
		$this->assertContains('lost', $result['errors'][3]);
		$this->assertNull(Asset::where('asset_tag', 'MN-0300')->first());
	}

	public function testSkipsARowWithoutTagNameOrType()
	{
		$result = $this->importer->import($this->csv(array(
			self::HEADER,
			',Spare keyboard,peripheral,,,in_stock,',
			'KB-0001,,peripheral,,,in_stock,',
		)));

		$this->assertSame(0, $result['created']);
		$this->assertSame(2, $result['skipped']);
		$this->assertSame(array(2, 3), array_keys($result['errors']));
		$this->assertSame(0, Asset::count());
	}

	public function testAnUnknownUsernameLeavesTheAssetUnassignedWithAWarning()
	{
		$result = $this->importer->import($this->csv(array(
			self::HEADER,
			'LT-0101,ThinkPad T440,laptop,PC0ABC12,HQ 2nd floor,in_use,nobody',
		)));

		$this->assertSame(1, $result['created']);
		$this->assertSame(0, $result['skipped']);
		$this->assertSame(array(), $result['errors']);
		$this->assertSame(array(2), array_keys($result['warnings']));
		$this->assertContains('nobody', $result['warnings'][2]);
		$this->assertNull(Asset::where('asset_tag', 'LT-0101')->first()->assigned_user_id);
	}

	public function testColumnOrderFollowsTheHeaderAndBlankLinesAreIgnored()
	{
		$result = $this->importer->import($this->csv(array(
			"\xEF\xBB\xBF".'status,asset_tag,name,type,serial,location,assigned_username',
			'',
			'in_stock,"PR-0008","Printer, colour",printer,,,',
		)));

		$this->assertSame(1, $result['created']);
		$asset = Asset::where('asset_tag', 'PR-0008')->first();
		$this->assertSame('Printer, colour', $asset->name);
		$this->assertSame('in_stock', $asset->status);
	}

	public function testMissingHeaderColumnThrows()
	{
		$this->setExpectedException('Helpdesk\Assets\CsvFormatException', 'status');

		$this->importer->import($this->csv(array(
			'asset_tag,name,type,serial,location,assigned_username',
			'LT-0101,ThinkPad T440,laptop,PC0ABC12,HQ 2nd floor,carol',
		)));
	}

	public function testAFileWithoutAHeaderThrows()
	{
		$this->setExpectedException('Helpdesk\Assets\CsvFormatException');

		$this->importer->import($this->csv(array(
			'LT-0101,ThinkPad T440,laptop,PC0ABC12,HQ 2nd floor,in_use,carol',
		)));
	}

	public function testAMissingFileThrows()
	{
		$this->setExpectedException('Helpdesk\Assets\CsvFormatException', 'not readable');

		$this->importer->import(sys_get_temp_dir().'/no-such-assets.csv');
	}

}
