<?php
namespace izzum\statemachine;
use izzum\statemachine\utils\EntityNull;
use izzum\command\ExceptionCommand;
use izzum\command\Command;
use izzum\command\Null;
use izzum\statemachine\builder\ModelBuilder;
use izzum\statemachine\utils\Utils;

/**
 * @group statemachine
 * @group state
 * @author rolf
 *
 */
class UtilsTest extends \PHPUnit_Framework_TestCase {
    
    /**
     * @test
     */
    public function shouldGetCommandWithEntity(){
    
    	$command_name = 'izzum\statemachine\IncreaseId';
    	$entity = new \stdClass();
    	$entity->id = 0;
    	$entity->event = null;
    	//modelbuilder always returns the model we give it in the constructor
    	$context = new Context(new Identifier('1','test'), new ModelBuilder($entity));
    	$event = null;
    	
    	$command = Utils::getCommand($command_name, $context, $event);
    	$this->assertTrue(is_a($command, 'izzum\command\Composite'));
    	$this->assertContains('IncreaseId', $command->toString());
    	$this->assertEquals(0, $entity->id);
    	$this->assertEquals(null, $entity->event);
    	$command->execute();
    	$this->assertEquals(1, $entity->id);
    	$this->assertEquals(null, $entity->event);
    }
    
    /**
     * @test
     */
    public function shouldGetCommandWithEntityAndEvent(){
    
    	$command_name = 'izzum\statemachine\IncreaseId';
    	$entity = new \stdClass();
    	$entity->id = 0;
    	$entity->event = null;
    	//modelbuilder always returns the model we give it in the constructor
    	$context = new Context(new Identifier('1','test'), new ModelBuilder($entity));
    	$event = 'event';
    	 
    	$command = Utils::getCommand($command_name, $context, $event);
    	$this->assertTrue(is_a($command, 'izzum\command\Composite'));
    	$this->assertContains('IncreaseId', $command->toString());
    	$this->assertEquals(0, $entity->id);
    	$this->assertEquals(null, $entity->event);
    	$command->execute();
    	$this->assertEquals(1, $entity->id);
    	$this->assertEquals($event, $entity->event);
    }
    
    /**
     * @test
     */
    public function shouldGetCompositeCommand(){
    
    	//id should be increased three times
    	$command_name = 'izzum\statemachine\IncreaseId,izzum\statemachine\IncreaseId,izzum\statemachine\IncreaseId';
    	$entity = new \stdClass();
    	$entity->id = 0;
    	$entity->event = null;
    	//modelbuilder always returns the model we give it in the constructor
    	$context = new Context(new Identifier('1','test'), new ModelBuilder($entity));
    	$event = 'event';
    
    	$command = Utils::getCommand($command_name, $context, $event);
    	$this->assertTrue(is_a($command, 'izzum\command\Composite'));
    	$this->assertContains('IncreaseId', $command->toString());
    	$this->assertEquals(0, $entity->id);
    	$this->assertEquals(null, $entity->event);
    	$command->execute();
    	$this->assertEquals(3, $entity->id);
    	$this->assertEquals($event, $entity->event);
    	$command->execute();
    	$this->assertEquals(6, $entity->id);
    	$this->assertEquals($event, $entity->event);
    }
    
    /**
     * @test
     */
    public function shouldGetNullCommand(){
    
    	$command_name = '';
    	$context = new Context(new Identifier('1','test'));
    	$event = '';
    	 
    	$command = Utils::getCommand($command_name, $context, $event);
    	$this->assertTrue(is_a($command, 'izzum\command\Null'));
    	
    	$command_name = null;
    	$context = new Context(new Identifier('1','test'));
    	$event = 'event';
    	
    	$command = Utils::getCommand($command_name, $context, $event);
    	$this->assertTrue(is_a($command, 'izzum\command\Null'));
    	 
    }
    
    /**
     * @test
     */
    public function shouldGetExceptionForInvalidCommand(){
    	$command_name = 'izzum\statemachine\CannotCreate';
    	$context = new Context(new Identifier('1','test'));
    	$event = '';
    	
    	try {
    		$command = Utils::getCommand($command_name, $context, $event);
    		$this->fail('should not come here, command should throw exception on failure');
    	} catch (Exception $e) {
    		$this->assertEquals(Exception::COMMAND_CREATION_FAILURE, $e->getCode());
    		$this->assertContains('cannot create', $e->getMessage());
    		$this->assertContains('objects to construction', $e->getMessage());
    		//echo $e->getMessage() . PHP_EOL;
    	}
    }
    
    /**
     * @test
     */
    public function shouldGetExceptionForNonExistingCommand(){
    
    	$command_name = 'bogus';
    	$context = new Context(new Identifier('1','test'));
    	$event = '';
    
    	try {
    		$command = Utils::getCommand($command_name, $context, $event);
    		$this->fail('should not come here, command does not exist');
    	} catch (Exception $e) {
    		$this->assertEquals(Exception::COMMAND_CREATION_FAILURE, $e->getCode());
    		$this->assertContains('class does not exist', $e->getMessage());
    		//echo $e->getMessage() . PHP_EOL;
    	}
    }
    
    /**
     * @test
     */
    public function shouldReturnCorrectTransitionName(){
        $from = 'state-from';
        $to = 'state-to';
        $this->assertEquals($from . Utils::STATE_CONCATENATOR . $to, Utils::getTransitionName($from, $to));
    }
    
    /**
     * @test
     * @group regex
     */
    public function shouldReturnRegexState(){
    	$name = 'regex:.*';
        $regex = new State($name);
        $this->assertTrue(Utils::isRegex($regex));
    }
    
    /**
     * @test
     * @group regex
     */
    public function shouldNotReturnRegexState(){
        $name = 'rege:.*';
        $regex = new State($name);
        $this->assertFalse(Utils::isRegex($regex));
    }
    
    /**
     * @test
     * @group regex
     */
    public function shouldMatchValidRegex(){
        $name = 'regex:/.*/';//only allow regexes between regex begin and end markers
        $regex = new State($name);
        $target = new State('aa');
        $this->assertTrue(Utils::matchesRegex($regex, $target), 'only allow regexes between regex begin and end markers');
        
        
        $name = 'regex:/a|b/';//allow regexes without regex begin and end markers
        $regex = new State($name);
        $target = new State('b');
        $this->assertTrue(Utils::matchesRegex($regex, $target));
        
        
        $name = 'regex:/c|a|aa/';
        $regex = new State($name);
        $target = new State('aa');
        $this->assertTrue(Utils::matchesRegex($regex, $target));
        
        
        $name = 'regex:/action-.*/';
        $regex = new State($name);
        $target = new State('action-hero');
        $bad = new State('action_hero');
        $this->assertTrue(Utils::matchesRegex($regex, $target));
        $this->assertFalse(Utils::matchesRegex($regex, $bad));
        
        
        $name = 'regex:/go[o,l]d/';
        $regex = new State($name);
        $target = new State('gold');
        $bad = new State('golld');
        $this->assertTrue(Utils::matchesRegex($regex, $target));
        $this->assertFalse(Utils::matchesRegex($regex, $bad));
    }
    
    /**
     * @test
     * @group regex
     */
    public function shouldReturnArrayOfMatchedStates(){
        
        $a = new State('a');
        $b = new State('ab');
        $c = new State('ba');
        $d = new State('abracadabra');
        $e = new State('action-hero');
        $f = new State('action-bad-guy');
        $g = new State('ac');
        $targets = array($a, $b, $c, $d, $e, $f, $g);
        
        $regex = new State('regex:/.*/');
        $this->assertEquals($targets, Utils::getAllRegexMatchingStates($regex, $targets));

        
        $regex = new State('regex:/^a.*/');
        $this->assertEquals(array($a, $b, $d, $e, $f, $g), Utils::getAllRegexMatchingStates($regex, $targets));
        
        $regex = new State('regex:/^a.+/');
        $this->assertEquals(array($b, $d, $e, $f, $g), Utils::getAllRegexMatchingStates($regex, $targets));
        
        $regex = new State('regex:/^a.*a.+$/');
        $this->assertEquals(array($d, $f), Utils::getAllRegexMatchingStates($regex, $targets));
        
        $regex = new State('regex:/^ac.*-.+$/');
        $this->assertEquals(array($e, $f), Utils::getAllRegexMatchingStates($regex, $targets));
        
        $regex = new State('ac');
        $this->assertFalse(Utils::isRegex($regex));
        $this->assertEquals(array($g), Utils::getAllRegexMatchingStates($regex, $targets), 'non regex state');

    }
    
    
    
    
   
}

//helper class, increases the id on an entity when executed.
class IncreaseId extends Command {
	private $entity;
	private $event;
	public function __construct($entity)
	{
		$this->entity = $entity;
	}
	public function setEvent($event)
	{
		$this->event = $event;
	}
	
	protected function _execute()
	{
		//proof that we can manipulate the entity and that 'setEvent' was called.
		$this->entity->id += 1;
		$this->entity->event = $this->event;
	}
}

//helper class, throws exception on execution
class CannotCreate extends Command {
	public function __construct($entity)
	{
		throw new Exception("cannot create");
	}

	protected function _execute(){}
}
