<?php
namespace yiiext\behaviors\EActiveRecordExtendedAttributesBehaviorTest\tests;

define('TEST_NAMESPACE', 'yiiext\behaviors\EActiveRecordExtendedAttributesBehaviorTest\tests');

if (!defined('YII_PATH')) {
	$yii = dirname(__FILE__).'/vendor/yiisoft/yii/framework/yiit.php';
	require_once($yii);
}

require_once(dirname(__FILE__).'/EActiveRecordExtendedAttributesBehavior.php');

\PHP_CodeCoverage_Filter::$blacklistClassNames = array();

class EActiveRecordExtendedAttributesBehaviorTest extends \CTestCase {
	public $dbFile;
	protected $migration;

	/**
	 * set up environment with yii application and migrate up db
	 */
	public function setUp() {
		$basePath=dirname(__FILE__).'/tmp';
		if (!file_exists($basePath))
			mkdir($basePath, 0777, true);
		if (!file_exists($basePath.'/runtime'))
			mkdir($basePath.'/runtime', 0777, true);

		// create webapp
		if (\Yii::app()===null) {
			\Yii::createWebApplication(array(
				'basePath'=>$basePath,
			));
		}
		\CActiveRecord::$db=null;

		if (!isset($_ENV['DB']) || $_ENV['DB'] == 'sqlite')
		{
			if (!$this->dbFile)
				$this->dbFile = $basePath.'/test.'.uniqid(time()).'.db';
			\Yii::app()->setComponent('db', new \CDbConnection('sqlite:'.$this->dbFile));
		}
		elseif ($_ENV['DB'] == 'mysql')
			\Yii::app()->setComponent('db', new \CDbConnection('mysql:dbname=test;host=localhost', 'root'));
		elseif ($_ENV['DB'] == 'pgsql')
			\Yii::app()->setComponent('db', new \CDbConnection('pqsql:dbname=test;host=localhost', 'postgres'));
		else
			throw new \Exception('Unknown db. Only sqlite, mysql and pgsql are valid.');

		// create db
		$this->migration = new EActiveRecordExtendedAttributesBehaviorTestMigration();
		$this->migration->dbConnection = \Yii::app()->db;
		$this->migration->up();

	}

	/**
	 * migrate down db when test succeeds
	 */
	public function tearDown() {
		if (!$this->hasFailed() && $this->migration && $this->dbFile) {
			$this->migration->down();
			\Yii::app()->db->setActive(false);
			unlink($this->dbFile);
		}
	}
	public function testGetExtendedAttributes() {
		$user = $this->getUser();
		$user->r('profile')->set($p = new Profile());

		$attrs = array(
			'username' => $user->username,
			'dynamicAttribute' => $user->dynamicAttribute,
			'property' => $user->property,
			'profile' => $p,
		);

		$this->assertEquals($attrs, $user->getExtendedAttributes(array_keys($attrs)));
	}

	public function testSetExtendedAttributes() {
		$john = $this->getUser();

		$exAttrs = array(
			'username' => 'username Test',
			'property' => 'property test',
			'dynamicAttribute' => 'dynamic attributes',
		);

		$john->setExtendedAttributes($exAttrs);

		$this->assertEquals($exAttrs, $john->getExtendedAttributes(array_keys($exAttrs)));
	}

	public function testSetExtendedAttributesRelation() {
		$john = $this->getUser();

		$exAttrs = array(
			'profile' => new Profile(),
		);

		$john->setExtendedAttributes($exAttrs);

		$this->assertEqualsActiveRecordArrays($exAttrs, $john->getExtendedAttributes(array_keys($exAttrs)));
	}
	public function testSetExtendedAttributesRelationBelongsToSet() {
		$john = $this->getUser();
		$post = $this->getPost($john);
		$jane = $this->getUser();

		$this->assertEqualsActiveRecords($post->author, $john);

		$post->setExtendedAttributes(array('author' => $jane));
		$this->assertEquals($post->author_id, $jane->id);
		$post->refresh();

		//check that post is not saved
		$this->assertEqualsActiveRecords($post->author, $john);
	}

	public function testSetExtendedAttributesRelationManySet() {
		$john = $this->getUser();

		$exAttrs = array(
			'posts' => [$this->getPost(), $this->getPost()],
		);

		$john->setExtendedAttributes($exAttrs);

		$this->assertEqualsActiveRecordArrays($exAttrs['posts'], $john->posts);
	}

	public function testSetExtendedAttributesRelationManySetAdd() {
		$posts = [$this->getPost(), $this->getPost()];
		$john = $this->getUser();
		$john->r('posts')->add($posts[0]);

		$exAttrs = array(
			'posts' => $posts[1],
		);

		$john->setExtendedAttributes($exAttrs, \EActiveRecordExtendedAttributesBehavior::RELATION_MODE_ADD);

		$this->assertEqualsActiveRecordArrays($posts, $john->posts);
	}

	public function testSetExtendedAttributesRelationManySetAddArray() {
		$posts = [$this->getPost(), $this->getPost(), $this->getPost()];
		$john = $this->getUser();
		$john->r('posts')->add($posts[0]);

		$exAttrs = array(
			'posts' => [$posts[1], $posts[2]],
		);

		$john->setExtendedAttributes($exAttrs, \EActiveRecordExtendedAttributesBehavior::RELATION_MODE_ADD);

		$this->assertEqualsActiveRecordArrays($posts, $john->posts);
	}

	public function testHasOneSet() {
		$john = $this->getUser();

		$p = new Profile();
		$john->r('profile')->set($p);

		$this->assertEqualsActiveRecords($p, $john->profile);
		$john->refresh();
		$this->assertEqualsActiveRecords($p, $john->profile);

		$p2 = new Profile();
		$john->r('profile')->set($p2);

		$this->assertEqualsActiveRecords($p2, $john->profile);
		$john->refresh();
		$this->assertEqualsActiveRecords($p2, $john->profile);

		//check old record is deleted
		$this->assertNull(Profile::model()->findByPk($p->id));
	}

	public function testHasOneGet() {
		$john = $this->getUser();

		$p = new Profile();
		$john->r('profile')->set($p);

		$john->refresh();

		$this->assertEqualsActiveRecords($p, $john->r('profile')->get());
	}

	public function testBelongsToSet() {
		$john = $this->getUser();

		$p = new Post();
		$p->title = 'hi testing!';
		$this->assertTrue($p->save());

		$p->r('author')->set($john);
		$this->assertEquals($john->id, $p->author_id);
		$this->assertEquals($john, $p->author);

		$p->refresh();
		$this->assertEqualsActiveRecords($john, $p->author);
		$p->r('author')->set(null);

		$this->assertNull($p->author);
		$this->assertNull($p->r('author')->get());
	}

	public function testBelongsToGet() {
		$p1 = $this->getPost();
		$u1 = $this->getUser($p1->author_id);

		$this->assertEqualsActiveRecords($u1, $p1->r('author')->get());
		$this->assertEqualsActiveRecords($p1->author, $p1->r('author')->get());
	}

	public function testBelongsToSetOnNewRecord() {
		$john = $this->getUser();

		$p = new Post();
		$p->title = 'hi testing!';
		$p->r('author')->set($john);
		$this->assertTrue(!!$p->id); //saved

		$p->refresh();
		$this->assertEqualsActiveRecords($john, $p->author);
	}

	public function testHasManyGet() {
		$john = $this->getUser(1);
		$jane = $this->getUser(2);

		$johnPosts = [];
		$johnPosts[] = $this->getPost($john);
		$johnPosts[] = $this->getPost($john);

		$janePosts = [$this->getPost($jane)];

		$this->assertEqualsActiveRecordArrays($johnPosts, $john->r('posts')->getAll());
		$this->assertEqualsActiveRecords($johnPosts[0], $john->r('posts')->getByPk($johnPosts[0]->id));

		$this->assertNull($john->r('posts')->getByPk($janePosts[0]->id));
	}

	public function testHasManyAdd() {
		$john = $this->getUser(1);
		$jane = $this->getUser(2);
		$johnPost1 = $this->getPost($john);
		$post = $this->getPost($jane);
		$janePost = $this->getPost($jane);

		$this->assertEqualsActiveRecords($jane, $post->author);

		$john->r('posts')->add($post);

		$this->assertEqualsActiveRecordArrays([$post], $john->posts);

		$this->assertEqualsActiveRecords($jane, $post->author);

		$post->refresh();
		$this->assertEqualsActiveRecords($john, $post->author);

		$janePost->refresh();
		$this->assertEqualsActiveRecords($jane, $janePost->author);
	}

	static public function hasManyRelations() {
		return [
			['posts'],
			['posts2'],
		];
	}

	/**
	 * @dataProvider hasManyRelations
	 */
	public function testHasManySet($relation) {
		if ($relation == 'posts') {
			$getPost = 'getPost';
			$model = Post::model();
		} else {
			$getPost = 'getPost2';
			$model = Post2::model();
		}

		$john = $this->getUser(1);
		$jane = $this->getUser(2);
		$janePosts = [];
		$janePosts[] = $this->$getPost($jane);
		$janePosts[] = $this->$getPost($jane);

		$oldPosts = [];
		$oldPosts[] = $this->$getPost();
		$oldPosts[] = $this->$getPost();

		$john->r($relation)->set($oldPosts);

		$this->assertEqualsActiveRecordArrays($oldPosts, $john->$relation);
		$john->refresh();
		$this->assertEqualsActiveRecordArrays($oldPosts, $john->$relation);

		$john->refresh();

		$newPosts = [];
		$newPosts[] = $oldPosts[0];
		$newPosts[] = $this->$getPost($john);
		$newPosts[] = $this->$getPost($john);

		$john->r($relation)->set($newPosts);
		$this->assertEqualsActiveRecordArrays($newPosts, $john->$relation);
		$john->refresh();
		$this->assertEqualsActiveRecordArrays($newPosts, $john->$relation);

		$this->assertEqualsActiveRecords($oldPosts[0], $model->findByPk($oldPosts[0]->primaryKey));
		$this->assertNull($model->findByPk($oldPosts[1]->primaryKey));

		$this->assertEqualsActiveRecordArrays($janePosts, $model->findAllByPk([$janePosts[0]->primaryKey, $janePosts[1]->primaryKey]));
	}

	public function testManyManyAdd() {
		$post = $this->getPost();
		$category = $this->getCategory();
		$category2 = $this->getCategory();
		$post->r('categories')->add($category);

		$this->assertEqualsActiveRecordArrays([$category], $post->categories);

		$post->refresh();
		$this->assertEqualsActiveRecordArrays([$category], $post->categories);

		$post->r('categories')->set([]);

		$post->refresh();
		$post->r('categories')->add($category, 'index1');
		$this->assertEqualsActiveRecordArrays(['index1' => $category], $post->categories);

		$post->r('categories')->add($category2, 'index2');
		$this->assertEqualsActiveRecordArrays(['index1' => $category, 'index2' => $category2], $post->categories);
	}

	static public function manyManyRelation() {
		return array(
			array('categories'),
			array('categories2'),
		);
	}

	/**
	 * testManyManySet
	 *
	 * @dataProvider manyManyRelation
	 * @return void
	 */
	public function testManyManySet($relation) {
		$post = $this->getPost();
		$category = $this->getCategory();
		$newCats = [];
		$newCats[] = $this->getCategory();
		$newCats[] = $this->getCategory();
		$post->r($relation)->set([$category]);

		$this->assertEqualsActiveRecordArrays([$category], $post->$relation);

		$post->refresh();
		$this->assertEqualsActiveRecordArrays([$category], $post->$relation);

		$post->r($relation)->set($newCats);
		$this->assertEqualsActiveRecordArrays($newCats, $post->$relation);

		$post->refresh();
		$this->assertEqualsActiveRecordArrays($newCats, $post->$relation);

		$post->r($relation)->set([]);
		$this->assertEqualsActiveRecordArrays([], $post->$relation);

		$post->refresh();
		$this->assertEqualsActiveRecordArrays([], $post->$relation);
		$this->assertEqualsActiveRecordArrays([], $post->r($relation)->getAll());
	}

	/**
	 * testManyManySet
	 *
	 * @dataProvider manyManyRelation
	 * @return void
	 */
	public function testManyManyGet($relation) {
		$post = $this->getPost();
		$category = $this->getCategory();

		$newCats = [];
		$newCats[] = $this->getCategory();
		$newCats[] = $this->getCategory();
		$post->r($relation)->set($newCats);

		$post->refresh();
		$this->assertEqualsActiveRecordArrays($newCats, $post->r($relation)->getAll());

		$post->refresh();
		$this->assertEqualsActiveRecords($newCats[0], $post->r($relation)->getByPk($newCats[0]->id));
		$this->assertNull($post->r($relation)->getByPk($category->id));
	}


	public function assertEqualsActiveRecords(\CActiveRecord $record1, \CActiveRecord $record2) {
		$this->assertEquals(get_class($record1), get_class($record2), 'Records have different classes');
		$this->assertEquals($record1->id, $record2->id, 'Records have different ids');
	}

	public function assertEqualsActiveRecordArrays(array $ar1, array $ar2) {
		$this->assertCount(count($ar1), $ar2);
		while ($r1 = array_shift($ar1)) {
			$r2 = array_shift($ar2);
			$this->assertEqualsActiveRecords($r1, $r2);
		}
	}

	protected function getUser($id = 1) {
		if ($user = User::model()->findByPk($id)) {
			return $user;
		}

		$user = new User();
		$user->id = $id;
		$user->username = 'Username' . $id;
		$user->password = 'password' . $id;
		$user->email = 'email'.$id.'@example.com';
		$this->assertTrue($user->save());
		return $user;
	}
	protected function getProfile($id = null) {
		$profile = new Profile();
		if ($id) {
			$profile->id = $id;
		}
		$this->assertTrue($profile->save());
		return $profile;
	}

	protected function getPost($user = null, $id = null, $class = 'Post') {
		if (!$user) {
			$user = $this->getUser();
		}
		$n = uniqid();
		$p = $class === 'Post' ? new Post() : new Post2();
		if ($id) {
			$p->id = $id;
			$p->key1 = $id;
			$p->key2 = $id;
		} else {
			$p->key1 = $n;
			$p->key2 = $n;
		}
		$p->title = 'title '.$n;
		$p->content = 'content '.$n;
		$p->author_id = $user->id;
		$this->assertTrue($p->save());
		return $p;
	}

	protected function getPost2($user = null, $id = null) {
		return $this->getPost($user, $id, 'Post2');
	}

	protected function getCategory($id = null) {
		$c = new Category();
		if ($id) {
			$c->id = $id;
		}
		if ($id) {
			$c->id = $id;
			$c->key1 = $id;
			$c->key2 = $id;
		} else {
			$n = uniqid();
			$c->key1 = $n;
			$c->key2 = $n;
		}
		$this->assertTrue($c->save());
		return $c;
	}
}

class EActiveRecordExtendedAttributesBehaviorTestMigration extends \CDbMigration {
	private $relationTableDropped = true;
	public function up() {
		ob_start();
		// these are the tables from yii definitive guide
		// http://www.yiiframework.com/doc/guide/1.1/en/database.arr
		$this->createTable('user', array(
			 'id' => 'pk',
             'username'=>'string',
             'password'=>'string',
             'email'=>'string',
		));
		$this->createTable('post', array(
             'id'=>'pk',
			 'key1'=>'string',
			 'key2'=>'string',
             'title'=>'string',
             'content'=>'text',
             'create_time'=>'timestamp',
             'author_id'=>'integer',
             'FOREIGN KEY (author_id) REFERENCES tbl_user(id)',
		));
		$this->createTable('profile', array(
			 'id' => 'pk',
			 'user_id' => 'integer',
			 'FOREIGN KEY (user_id) REFERENCES user(id)',
		));
		$this->createTable('post_category', array(
			 'post_id' => 'integer',
			 'category_id'=>'integer',
			 'PRIMARY KEY(post_id, category_id)',
			 'FOREIGN KEY (post_id) REFERENCES post(id)',
			 'FOREIGN KEY (category_id) REFERENCES category_id(id)',
		));
		$this->relationTableDropped = false;
		$this->createTable('category', array(
			 'id' => 'pk',
             'key1'=>'string',
             'key2'=>'string',
		));
		$this->createTable('post_category2', array(
			 'post_key1' => 'string',
			 'post_key2' => 'string',
			 'category_key1' => 'string',
			 'category_key2' => 'string',
			 'PRIMARY KEY(post_key1, post_key2, category_key1, category_key2)',
			 'FOREIGN KEY (post_key1, post_key2) REFERENCES post(key1, key2)',
			 'FOREIGN KEY (category_key1, category_key2) REFERENCES category(key1, key2)',
		));
		ob_end_clean();
	}

	public function down() {
		ob_start();
		if (!$this->relationTableDropped) {
			$this->dropTable('post_category');
		}
		$this->dropTable('category');
		$this->dropTable('post');
		$this->dropTable('user');
		ob_end_clean();
	}

	public function dropRelationTable() {
		ob_start();
		$this->dropTable('post_category');
		$this->relationTableDropped = true;
		ob_end_clean();
	}
}


class User extends \CActiveRecord {
	private $_dynamicAttribute = 'default dyn';
	public $property = 'default property';

	public static function model($className = __CLASS__) {
		return parent::model($className);
	}
	public function behaviors() {
		return array('r' => 'EActiveRecordExtendedAttributesBehavior');
	}
	public function relations() {
		return array(
			'posts' => array(self::HAS_MANY, TEST_NAMESPACE.'\Post', 'author_id'),
			'posts2' => array(self::HAS_MANY, TEST_NAMESPACE.'\Post2', 'author_id'),
			'profile' => array(self::HAS_ONE, TEST_NAMESPACE.'\Profile', 'user_id'),
		);
	}
	public function setDynamicAttribute($value) {
		$this->_dynamicAttribute = $value;
	}

	public function getDynamicAttribute() {
		return $this->_dynamicAttribute;
	}
}

class Post extends \CActiveRecord {
	public static function model($className = __CLASS__) {
		return parent::model($className);
	}
	public function behaviors() {
		return array('r' => 'EActiveRecordExtendedAttributesBehavior');
	}
	public function relations() {
		return array(
			'author' => array(self::BELONGS_TO, TEST_NAMESPACE.'\User', 'author_id'),
			'categories' => array(self::MANY_MANY, TEST_NAMESPACE.'\Category', 'post_category(post_id, category_id)'),
			'categories2' => array(self::MANY_MANY, TEST_NAMESPACE.'\Category', 'post_category2(post_key1, post_key2, category_key1, category_key2)'),
		);
	}
}

class Post2 extends \CActiveRecord {
	public static function model($className = __CLASS__) {
		return parent::model($className);
	}
	public function behaviors() {
		return array('r' => 'EActiveRecordExtendedAttributesBehavior');
	}
	public function tableName() {
		return 'post';
	}
	public function primaryKey() {
		return array('key1', 'key2');
	}
	public function relations() {
		return array(
		);
	}
}
class Profile extends \CActiveRecord {
	public static function model($className = __CLASS__) {
		return parent::model($className);
	}
	public function behaviors() {
		return array('r' => 'EActiveRecordExtendedAttributesBehavior');
	}
	public function relations() {
		return array(
			'user' => array(self::BELONGS_TO, TEST_NAMESPACE.'\User', 'user_id'),
		);
	}
}

class Category extends \CActiveRecord {
	public static function model($className = __CLASS__) {
		return parent::model($className);
	}
	public function behaviors() {
		return array('r' => 'EActiveRecordExtendedAttributesBehavior');
	}
}
