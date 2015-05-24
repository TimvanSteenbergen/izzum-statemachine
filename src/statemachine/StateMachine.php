<?php
namespace izzum\statemachine;
use izzum\statemachine\Context;
use izzum\statemachine\State;
use izzum\statemachine\Transition;
use izzum\statemachine\Exception;
use izzum\statemachine\utils\Utils;

/**
 * StateMachine class.
 *
 * The statemachine is used to execute transitions from certain states to other
 * states, for an entity that represents a domain object, by applying guard logic 
 * to see if a transition is allowed and transition logic to process the transition.
 *
 *
 * PACKAGE PHILOSOPHY
 * This whole package strives to follow the open/closed principle, making it
 * open for extension (adding your own logic through subclassing) but closed
 * for modification. For that purpose, we provide Loader interfaces, Builders
 * and Persistence Adapters for the backend implementation. States and
 * Transitions can also be subclassed to store data and provide functionality.
 *
 * Following the same philosophy: if you want more functionality in the
 * statemachine, you can use the provided methods and override them in your
 * subclass. multiple hooks are provided. Most functionality should be provided by
 * using the diverse ways of interacting with the statemachine: closures can be injected,
 * commands can be injected, callbacks can be defined and hooks can be overriden.
 *
 * We have provided a fully functional, normalized and indexed set of tables
 * for the postgresql relational database to function as a backend to store all
 * relevant information for a statemachine. Memory and session backend adapters
 * can be used to temporarily store the state information.
 * 
 * The implementation details of this machine make it that it can act both as
 * a mealy machine and as a moore machine. the concepts can be mixed and
 * matched.
 * 
 * Examples are provided in the 'examples' folder and serve to highlight some of the
 * features and the way to work with the package. The unittests can serve as examples too, 
 * including interaction with databases.
 *
 *
 * ENVIRONMENTS OF USAGE:
 * - 1: a one time process:
 * -- on webpages where there are page refreshes in between (use session/pdo adapter)
 * see 'examples/session'
 * -- an api where succesive calls are made (use pdo adapter)
 * -- cron jobs (pdo adapter)
 * - 2: a longer running process:
 * -- a php daemon that runs as a background process (use memory adapter)
 * -- an interactive shell environment (use memory adapter)
 * see 'examples/interactive'
 * 
 * 
 * DESCRIPTION OF THE 4 MAIN MODELS OF USAGE:
 * - 1: DELEGATION: Use an existing domain model. You can use a subclass of the
 * AbstractFactory to get a StateMachine, since that will put the creation of
 * all the relevant Contextual classes and Transitions in a reusable model.
 * usage: This is the most formal, least invasive and powerful model of usage, but the most complex.
 * Use Rules and Commands to interact with your domain model without altering a domain model
 * to work with the statemachine.
 * see 'examples/trafficlight'
 * - 2: INHERITANCE: Subclass a statemachine. Build the full Context and all transitions in
 * the constructor of your subclass (which could be a domain model itself) and
 * call the parent constructor. use the hooks to provide functionality (optionally a ModelBuilder and callbacks).
 * usage: This is the most flexible model of usage, but mixes your domain logic with the statemachine.
 * see 'examples/inheritance'
 * - 3: COMPOSITION: Use object composition. Instantiate and build a statemachine in a domain
 * model and build the full Context, Transitions and statemachine there. Use a ModelBuilder and callbacks
 * to drive functionality.
 * usage: This is a good mixture between encapsulating statemachine logic and flexibility/formal usage. 
 * see 'examples/composition'
 * - 4: STANDALONE: Use the statemachine as is, without any domain models. Your application will use it and inspect the
 * statemachine to act on it. Use Closures to provide functionality
 * usage: This is the easiest model of usage, but the least powerful.
 * see 'examples/interactive'
 *
 *
 *
 * DESCRIPTION OF FULL TRANSITION ALGORITHM (and the usage modes where they apply)
 * 1. guard: _onCheckCanTransition($transition, $event) //hook: inheritance.
 * 2. guard: $entity->onCheckCanTransition($transition, $event) // callable: delegation, composition, inheritance
 * 3. guard: $transition->can($context) // check rule: delegation, composition, inheritance, standalone
 * !!!  If all (existing) guards return true, then the transition is allowed. return true/false to allow/disallow transition.
 * 4. logic: _onExitState($transition, $event) //hook: inheritance
 * 5. logic: $entity->onExitState($transition, $event) //callable: delegation, composition, inheritance
 * 6. logic: $state_from->exitAction($event) //execute command: delegation, composition, inheritance, standalone
 * 6. logic: state entry: $closure($entity, $event) // closure: standalone, delegation, composition, inheritance
 * 7. logic: _onTransition($transition, $event) //hook: inheritance
 * 7. logic: $entity->onEvent($transition, $event) //callable: delegation, composition, inheritance, only if transition was event driven
 * 9. logic: $entity->on<$event>($transition, $event) //callable: delegation, composition, inheritance, only if transition was event driven
 * 10. logic: $entity->onTransition($transition, $event) //callable: delegation, composition, inheritance
 * 11. logic: $transition->process(event) //execute command: delegation, composition, inheritance, standalone
 * 12. logic: $entity->onEnterState($transition, $event) //callable: delegation, composition, inheritance
 * 13. logic: $state_to->entryAction($event) //execute command: delegation, composition, inheritance, standalone
 * 14. logic: state exit: $closure($entity, $event) // closure: standalone, delegation, composition, inheritance
 * 14. logic: _onEnterState($transition, $event) //hook: inheritance
 *
 * each hook can be overriden and implemented in a subclass, providing
 * functionality that is specific to your application. This allows you to use
 * the core mechanisms of the izzum package and extend it to your needs.
 *
 * each rule and command will be injected with the entity and if they implement
 * the method 'setEvent' the event will be set in case the transition was called
 * with the event.
 *
 * each callable might be implemented on the entity/domain object and will be called, only if
 * available, with the $transition and $event arguments.
 * 
 *  Each transition will take place only if the transition logic (Rule, hooks &
 * callables) allows it. Each transition will then execute specific logic
 * (Command, hooks & callables).
 *
 * for simple applications, the use of callables and hooks is advised since it
 * is easier to setup and use with existing code.
 *
 * for more formal applications, the use of rules and commands is advised since
 * it allows for better encapsulated (and tested) business logic and more flexibility in
 * configuration via a database or config file.
 * 
 * The statemachine should be loaded with States and Transitions, which define
 * from what state to what other state transitions are allowed. The transitions
 * are checked against guard clauses (in the form of a business Rule instance
 * and hooks and callables) and transition logic (in the form of a Command
 * instance and hooks and callables).
 *
 * Transitions can also trigger an exit action for the current state and an
 * entry action for the new state (also via Command instances and hooks and
 * callables). This allows you to implement logic that is dependent on the
 * State and independent of the Transition.
 *
 * RULE/COMMAND logic
 * The Rule checks if the domain model (or it's derived data) applies and
 * therefore allows the transition, after which the Command is executed that can
 * actually alter data in the underlying domain models, call services etc.
 * Rules should have NO side effects, since their only function is to check if a 
 * Transition is allowed. Commands do the heavy lifting for executing logic that
 * relates to a transition.
 * 
 * EXCEPTIONS
 * All high level interactions that a client conducts with a statemachine
 * should expect exceptions since runtime exceptions could bubble up from the
 * application specific classes from your domain (eg: calling services, database interactions etc). 
 * Exceptions that bubble up from this statemachine are always izzum\statemachine\Exception types.
 *
 * NAMING CONVENTIONS
 * - A good naming convention for states is to use lowercase-hypen-seperated names:
 * new, waiting-for-input, starting-order-process, enter-cancel-flow, done
 * - A good naming convention for transitions is to bind the input and exit state
 * with the string '_to_', which is done automatically by this package eg: 
 * new_to_waiting-for-input, new_to_done so you're able to call $statemachine->transition('new_to_done');
 * - A good naming convention for events (transition trigger names) is to use
 * lowercase-underscore-seperated names (or singe words) so your able to call
 * $machine->start() or $machine->event_name() or $machine->handle('event_name') 
 * 
 *
 * @author Rolf Vreijdenberger
 * @link https://en.wikipedia.org/wiki/Finite-state_machine
 * @link https://en.wikipedia.org/wiki/UML_state_machine
 */
class StateMachine {
    
    /**
     * The context instance that provides the context for the statemachine to
     * operate in.
     *
     * @var Context
     */
    private $context;
    
    /**
     * The available states.
     * these should be loaded via transitions
     * A hashmap where the key is the state name and the value is
     * the State.
     *
     * @var State[]
     */
    private $states = array();
    
    /**
     * The available transitions.
     * these should be loaded via the 'addTransition' method.
     * A hashmap where the key is the transition name and the value is
     * the Transition.
     *
     * @var Transition[]
     */
    private $transitions = array();
    
    /**
     * the current state
     *
     * @var State
     */
    private $state;
    
    // ######################## TRANSITION METHODS #############################
    

    /**
     * Constructor
     *
     * @see AbstractFactory for how to create a fully configured statemachine
     *     
     * @param Context $context
     *            a fully configured context providing all the relevant
     *            parameters/dependencies
     *            to be able to run this statemachine for an entity.
     *            The initial state will normally be retrieved from the backend
     *            if it is there.
     */
    public function __construct(Context $context)
    {
        // sets up bidirectional association
        $this->setContext($context);
    }

    /**
     * Apply a transition by name.
     *
     * this type of handling is found in moore machines.
     *
     * @param string $transition_name
     *            convention: <state-from>_to_<state-to>
     * @return boolean true if the transition was made
     * @throws Exception in case something went disastrously wrong or if the
     *         transition does not exist. An exception will lead to a (partially
     *         or fully) failed transition.
     * @link https://en.wikipedia.org/wiki/Moore_machine
     */
    public function transition($transition_name)
    {
        $transition = $this->getTransitionWithNullCheck($transition_name);
        return $this->performTransition($transition, null, true);
    }

    /**
     * Try to apply a transition from the current state by handling an event
     * string as a trigger.
     * If the event is applicable for a transition then that transition from the
     * current state will be applied.
     * If there are multiple transitions possible for the event, the transitions
     * will be tried until one of
     * them is possible.
     *
     * The event string itself will be set on the hooks, callables, rules and
     * command (if they implement
     * the 'setEvent($event)' method)
     *
     * This type of (event/trigger) handling is found in mealy machines.
     *
     * @param string $event
     *            in case the transition will be triggered by an event code
     *            (mealy machine)
     *            this will also match on the transition name
     *            (<state_to>_to_<state_from>)
     * @return bool true in case a transition was triggered by the event, false
     *         otherwise
     * @throws Exception in case something went horribly wrong
     * @link https://en.wikipedia.org/wiki/Mealy_machine
     * @link http://martinfowler.com/books/dsl.html for event handling
     *       statemachines
     */
    public function handle($event)
    {
        $transitioned = false;
        $transitions = $this->getCurrentState()->getTransitionsTriggeredByEvent($event);
        foreach ($transitions as $transition) {
            $transitioned = $this->performTransition($transition, $event, true);
            if ($transitioned)
                break;
        }
        return $transitioned;
    }

    /**
     * Have the statemachine do the first possible transition.
     * The first possible transition is based on the configuration of
     * the guard logic and the current state of the statemachine.
     *
     * TRICKY: Be careful when using this function, since all guard logic must
     * be mutually exclusive! If not, you might end up performing the state
     * transition with priority n when you really want to perform transition
     * n+1.
     *
     * An alternative is to use the 'transition' method to target 1 transition
     * specifically:
     * $statemachine->transition('a_to_b');
     * So you are always sure that you are actually doing the intented
     * transition instead of relying on the configuration and guard logic (which
     * *might* not be correctly implemented, leading to transitions that would
     * normally not be executed).
     *
     * @return boolean true if a transition was applied.
     * @throws Exception in case something went awfully wrong.
     *        
     */
    public function run()
    {
        try {
            $transitions = $this->getCurrentState()->getTransitions();
            foreach ($transitions as $transition) {
                $transitioned = $this->performTransition($transition, null, true);
                if ($transitioned) {
                    return true;
                }
            }
        } catch(Exception $e) {
            // will be rethrown
            $this->handlePossibleNonStatemachineException($e, Exception::SM_RUN_FAILED);
        }
        // no transition done
        return false;
    }

    /**
     * run a statemachine until it cannot run any transition in the current
     * state or until it is in a final state.
     *
     * when using cyclic graphs, you could get into an infinite loop between
     * states. design your machine correctly.
     *
     * preconditions:
     * - the transitions should be defined for each state
     * - the transitions should be allowed by the rules
     * - the transitions should be able to execute
     *
     * @return int the number of sucessful transitions made.
     * @throws Exception in case something went badly wrong.
     */
    public function runToCompletion()
    {
        $transitions = 0;
        try {
            $run = true;
            while ( $run ) {
                // run the first transition possible
                $run = $this->run();
                if ($run) {
                    $transitions++;
                }
            }
        } catch(Exception $e) {
            $this->handlePossibleNonStatemachineException($e, $e->getCode());
        }
        return $transitions;
    }

    /**
     * Check if an existing transition on the curent state is allowed by the
     * guard logic.
     *
     * @param string $transition_name
     *            convention: <state-from>_to_<state-to>
     * @return boolean
     */
    public function canTransition($transition_name)
    {
        $transition = $this->getTransition($transition_name);
        return $transition === null ? false : $this->doCheckCanTransition($transition);
    }

    /**
     * checks if one or more transitions are possible and/or allowed for the
     * current state when triggered
     * by an event
     *
     * @param string $event            
     * @return boolean false if no transitions possible or existing
     */
    public function canHandle($event)
    {
        $transitions = $this->getCurrentState()->getTransitionsTriggeredByEvent($event);
        foreach ($transitions as $transition) {
            if ($this->doCheckCanTransition($transition, $event)) {
                return true;
            }
        }
        return false;
    }

    /**
     * check if the current state has one or more transitions that can be
     * triggered by an event
     *
     * @param string $event            
     * @return boolean
     */
    public function hasEvent($event)
    {
        $transitions = $this->getCurrentState()->getTransitionsTriggeredByEvent($event);
        if (count($transitions) > 0) {
            return true;
        }
        return false;
    }
    
    // ######################## CORE TRANSITION & TEMPLATE METHODS
    // #############################
    

    /**
     * Perform a transition by specifiying the transitions' name from a state
     * that the transition is allowed to run.
     *
     * @param Transition $transition            
     * @param boolean $check_guards
     *            optional: to specify if we want to do the check to see
     *            if the transition is allowed or not. This is a performance
     *            optimalization
     *            so in case we call 'can' directly, we can use 'transition'
     *            directly
     *            after that without doing the checks (including expensive
     *            Rules) twice.
     * @param string $event
     *            optional in case the transition was triggered by an event code
     *            (mealy machine)
     * @return boolean true if the transition was succesful
     * @throws Exception in case something went horribly wrong
     * @link https://en.wikipedia.org/wiki/Template_method_pattern
     */
    private function performTransition(Transition $transition, $event = null, $check_guards = true)
    {
        // every method in this core routine has hook methods and
        // callbacks it can call during the execution phase of the
        // transition steps if they are available on the domain model.
        try {
            
            if ($check_guards === true) {
                if (!$this->doCheckCanTransition($transition, $event)) {
                    // one of the guards returned false or transition not found
                    // on current state.
                    return false;
                }
            }
            
            // state exit action: performed when exiting the state
            $this->doExitState($transition, $event);
            // the transition is performed, with the associated logic
            $this->doTransition($transition, $event);
            // state entry action: performed when entering the state
            $this->doEnterState($transition, $event);
        } catch(Exception $e) {
            $this->handlePossibleNonStatemachineException($e, Exception::SM_TRANSITION_FAILED, $transition);
        }
        return true;
    }

    /**
     * Template method to call a hook and to call a possible method
     * defined on the domain object/contextual entity
     *
     * @param Transition $transition            
     * @param string $event
     *            an event name if the transition was triggered by an event.
     * @return boolean if false, the transition and its' associated logic will
     *         not take place
     * @throws Exception in case something went horribly wrong
     */
    private function doCheckCanTransition(Transition $transition, $event = null)
    {
        try {
            // check if we have this transition on the current state.
            if (!$this->getCurrentState()->hasTransition($transition->getName())) {
                return false;
            }
            
            // possible hook so your application can place an extra guard on the
            // transition. possible entry~ or exit state type of checks can also
            // take place in this hook.
            if (!$this->_onCheckCanTransition($transition, $event)) {
                return false;
            }
            // a callable that is possibly defined on the domain model:
            // onCheckCanTransition
            if (!$this->callCallable($this->getContext()->getEntity(), 'onCheckCanTransition', $transition, $event)) {
                return false;
            }
            
            // this will check the Rule defined for the transition.if this final
            // guard Rule applies, then this is seen as a green light to start
            // the transition.
            if (!$transition->can($this->getContext())) {
                return false;
            }
        } catch(Exception $e) {
            $this->handlePossibleNonStatemachineException($e, Exception::SM_CAN_FAILED);
        }
        return true;
    }

    /**
     * the exit state action method
     *
     * @param Transition $transition            
     * @param string $event            
     */
    private function doExitState(Transition $transition, $event = null)
    {
        // hook for subclasses to implement
        $this->_onExitState($transition, $event);
        // a callable that is possibly defined on the domain model: onExitState
        $this->callCallable($this->getContext()->getEntity(), 'onExitState', $transition, $event);
        // executes the command associated with the state object
        $transition->getStateFrom()->exitAction($this->getContext(), $event);
    }

    /**
     * the transition action method
     *
     * @param Transition $transition            
     * @param string $event            
     */
    private function doTransition(Transition $transition, $event = null)
    {
        // hook for subclasses to implement
        $this->_onTransition($transition, $event);
        $entity = $this->getContext()->getEntity();
        if ($event) {
            // a callable that is possibly defined on the domain model: onEvent
            $this->callCallable($entity, 'onEvent', $transition, $event);
            // possibly defined on the domain model: on<$event>
            $this->callCallable($entity, $this->toValidMethodName('on' . $event), $transition, $event);
        }
        // a callable that is possibly defined on the domain model: onTransition
        $this->callCallable($entity, 'onTransition', $transition, $event);
        // executes the command associated with the transition object
        $transition->process($this->getContext(), $event);
        // this actually sets the state!
        $this->setState($transition->getStateTo());
    }

    /**
     * the enter state action method
     *
     * @param Transition $transition            
     * @param string $event            
     */
    private function doEnterState(Transition $transition, $event = null)
    {
        // a callable that is possibly defined on the domain model: onEnterState
        $this->callCallable($this->getContext()->getEntity(), 'onEnterState', $transition, $event);
        
        // executes the command associated with the state object
        $transition->getStateTo()->entryAction($this->getContext(), $event);
        
        // hook for subclasses to implement
        $this->_onEnterState($transition, $event);
    }
    
    // ######################## SUPPORTING METHODS #############################
    /**
     * All known/loaded states for this statemachine
     *
     * @return State[]
     */
    public function getStates()
    {
        return $this->states;
    }

    /**
     * get a state by name.
     *
     * @param string $name            
     * @return State or null if not found
     */
    public function getState($name)
    {
        return isset($this->states [$name]) ? $this->states [$name] : null;
    }

    /**
     * Add a state.
     *
     * @param State $state            
     */
    protected function addState(State $state)
    {
        $this->states [$state->getName()] = $state;
    }

    /**
     * sets the state as the current state and on the backend.
     * This should only be done:
     * - initially, right after a machine has been created, to set it in a
     * certain state if
     * the state has not been persisted before.
     * - when changing context (since this resets the current state) via
     * $machine->setState($machine->getCurrentState())
     *
     * This method allows you to bypass the transition guards and the transition
     * logic. no exit/entry/transition logic will be performed
     *
     * @param State $state            
     */
    public function setState(State $state)
    {
        $this->getContext()->setState($state->getName());
        $this->state = $state;
    }

    /**
     * gets the current state (or retrieve it from the backend if not set).
     *
     *
     * the state will be:
     * - the state that was explicitely set via setState
     * - the state we have moved to after the last transition
     * - the initial state. if we haven't had a transition yet and no current
     * state has been set
     * the initial state will be retrieved (the state with State::TYPE_INITIAL)
     *
     * @return State
     * @throws Exception in case there is no valid current state found
     */
    public function getCurrentState()
    {
        // do we have a current state?
        if ($this->state) {
            return $this->state;
        }
        // retrieve state from the context if we do not find any state set.
        $state = $this->getState($this->getContext()->getState());
        if (!$state) {
            // possible wrong configuration
            throw new Exception(sprintf("%s current state not found for state with name '%s'. %s", $this->toString(), $this->getContext()->getState(), 'are the transitions/states loaded and configured correctly?'), Exception::SM_NO_CURRENT_STATE_FOUND);
        }
        // state is found, set it and return
        $this->state = $state;
        return $this->state;
    }

    /**
     * Get the initial state, the only state with type State::TYPE_INITIAL
     *
     * This method can be used to 'add' the state information to the backend via
     * the context/persistence adapter when a machine has been initialized for
     * the first time.
     *
     * @param boolean $allow_null
     *            optional
     * @return State (or null or Exception, only when statemachine is improperly
     *         loaded)
     * @throws Exception if $allow_null is false an no inital state was found
     */
    public function getInitialState($allow_null = false)
    {
        $states = $this->getStates();
        foreach ($states as $state) {
            if ($state->isInitial()) {
                return $state;
            }
        }
        if ($allow_null) {
            return null;
        }
        throw new Exception(sprintf("%s no initial state found, bad configuration. %s", $this->toString(), 'are the transitions/states loaded and configured correctly?'), Exception::SM_NO_INITIAL_STATE_FOUND);
    }

    /**
     * All known/loaded transitions for this statemachine
     *
     * @return Transition[]
     */
    public function getTransitions()
    {
        return $this->transitions;
    }

    /**
     * get a transition by name.
     *
     * @param string $name
     *            convention: <state_from>_to_<state_to>
     * @return Transition or null if not found
     */
    public function getTransition($name)
    {
        return isset($this->transitions [$name]) ? $this->transitions [$name] : null;
    }

    /**
     * Add a fully configured transition to the machine.
     *
     * It is possible to add transition that have 'regex' states: states that
     * have a name in the format of 'regex:<regex-here>'. When adding a
     * transition with a regex state, it will be matched against all currently
     * known states if there is a match.
     * Self transitions for regex states are disallowed by default
     * since you would probably only want to do that explicitly. Regex states
     * can be both the 'to' and the 'from' state of a transition.
     *
     * Transitions from a 'final' type of state are not allowed.
     *
     * the order in which transitions are added matters insofar that when a
     * StateMachine::run() is called, the first Transition for the current State
     * will be tried first.
     *
     * Since a transition has complete knowledge about it's states,
     * the addition of a transition will also trigger the adding of the
     * to and from state on this class.
     *
     * this method can also be used to add a Transition directly (instead of via
     * a loader).
     * Make sure that transitions that share a common State use the same
     * instance of that State object and vice versa.
     *
     * @param Transition $transition            
     */
    public function addTransition(Transition $transition)
    {
        // check if we have regex states in the transition and get either the
        // original state back if it's not a regex ,or all currently known
        // states that match the regex.
        $from = $transition->getStateFrom();
        $all_from = Utils::getAllRegexMatchingStates($from, $this->getStates());
        $to = $transition->getStateTo();
        $all_to = Utils::getAllRegexMatchingStates($to, $this->getStates());
        $contains_regex = Utils::isRegex($from) || Utils::isRegex($to);
        $non_regex_transition = $transition;
        // loop trought all possible 'from' states
        foreach ($all_from as $from) {
            // loop through all possible 'to' states
            foreach ($all_to as $to) {
                
                if ($contains_regex && $from->getName() === $to->getName()) {
                    // disallow self transition for regexes and from final
                    // states
                    continue;
                }
                if ($contains_regex) {
                    /*
                     * get a copy of the current transition that has a regex (so
                     * we have a non-regex state) and delegate to $transition to
                     * get that copy in case it's a subclass of transition and
                     * we need to copy specific fields
                     */
                    $non_regex_transition = $transition->getCopy($from, $to);
                }
                $this->addTransitionWithoutRegex($non_regex_transition);
            }
        }
    }

    /**
     * Add the transition, after it has previously been checked that is did not
     * contain states with a regex.
     *
     * @param Transition $transition            
     */
    protected function addTransitionWithoutRegex(Transition $transition)
    {
        // don't allow transitions from a final state
        if ($transition->getStateFrom()->isFinal())
            return;
            
            // add transition or overwrite transition if it already exists
        $this->transitions [$transition->getName()] = $transition;
        $from = $transition->getStateFrom();
        if (!$this->getState($from->getName())) {
            $this->addState($from);
        }
        // transitions create bidirectional references to the States
        // when they are made, but here the States that are set on the machine
        // can actually be different instances from different transitions (eg:
        // a->b and a->c => we now have two State instances of a)
        // we therefore need to merge the transitions on the existing states.
        // The LoaderArray class does this for us by default, but we do it here
        // too, just in case a client decides to call the 'addTransition' method
        // directly without a loader.
        $state = $this->getState($from->getName());
        if (!$state->hasTransition($transition->getName())) {
            $state->addTransition($transition);
        }
        
        $to = $transition->getStateTo();
        if (!$this->getState($to->getName())) {
            $this->addState($to);
        }
        
        if ($this->state != null) {
            // we have a current state, so check if we need to set the 'current
            // state' to a fully configured state (with transitions) that we
            // retrieve from the loaded Transition (in case the client did not
            // set a fully configured state with the correct transition via
            // setState)
            if ($to->getName() == $this->state->getName()) {
                $this->state = $to;
            }
            if ($from->getName() == $this->state->getName()) {
                $this->state = $from;
            }
        }
    }

    /**
     * Get the current context
     *
     * @return Context
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * set the context on the statemachine and provide bidirectional
     * association.
     *
     * change the context for a statemachine that already has a context.
     * When the context is changed, but it is for the same statemachine (with
     * the same transitions), the statemachine can be used directly with the
     * new context.
     *
     * The current state is reset to whatever state the machine should be in
     * (either the initial state or the stored state) whenever a context change
     * is made.
     *
     * we can change context to:
     * - switch builders/persistence adapters at runtime
     * - reuse the statemachine for a different entity so we do not
     * have to load the statemachine with the same transition definitions
     *
     * @param Context $context            
     * @throws Exception
     */
    public function setContext(Context $context)
    {
        if ($this->getContext()) {
            // context already exists.
            if ($this->getContext()->getMachine() !== $context->getMachine()) {
                throw new Exception(sprintf("Trying to set context for a different machine. currently '%s' and new '%s'", $this->getContext()->getMachine(), $context->getMachine()), Exception::SM_CONTEXT_DIFFERENT_MACHINE);
            }
            
            // reset state TODO: move outside if statement
            $this->state = null;
        }
        $context->setStateMachine($this);
        $this->context = $context;
    }
    
    // #################### LOW LEVEL HELPER METHODS #########################
    

    /**
     * called whenever an exception occurs from inside 'performTransition()'
     * can be used for logging etc.
     *
     * @param Transition $transition            
     * @param Exception $e            
     */
    protected function handleTransitionException(Transition $transition, Exception $e)
    {
        // override if necessary to log exceptions or to add some extra info
        // to the underlying storage facility (for example, an exception will
        // not lead to a transition, so this can be used to indicate a failed
        // transition in some sort of history structure)
        $this->getContext()->setFailedTransition($transition, $e);
    }

    /**
     * Always throws an izzum exception (converts a non-izzum exception to an
     * izzum exception)
     *
     * @param \Exception $e            
     * @param int $code
     *            if the exception is not of type Exception, wrap it and use
     *            this code.
     * @param Transtion $transition
     *            optional. if set, we handle it as a transition exception too
     *            so it can be logged or handled
     * @throws Exception an izzum exception
     */
    protected function handlePossibleNonStatemachineException(\Exception $e, $code, $transition = null)
    {
        $e = Utils::wrapToStateMachineException($e, $code);
        if ($transition !== null) {
            $this->handleTransitionException($transition, $e);
        }
        throw $e;
    }

    /**
     * This method is used to trigger an event on the statemachine and
     * delegates the actuall call to the 'handle' method
     *
     * $statemachine->triggerAnEvent() actually calls
     * $this->handle('triggerAnEvent')
     *
     * This is also very useful if you use object composition to include a
     * statemachine
     * in your domain model. The domain model itself can then use it's own
     * __call
     * implementation to directly delegate to the statemachines' __call method
     *
     * $model->walk() will actuall call $model->statemachine->walk() which will
     * then call $model->statemachine->handle('walk');
     *
     * since transition event names default to the transition name, it is
     * possible to
     * execute this kind of code (if the state names contain allowed
     * characters):
     * $statemachine-><state_from>_to_<state_to>();
     * $statemachine->eventName();
     *
     *
     * @param string $name
     *            the name of the unknown method called
     * @param array $arguments
     *            an array of arguments (if any)
     * @return bool true in case a transition was triggered by the event, false
     *         otherwise
     * @throws Exception in case the transition is not possible via the guard
     *         logic (Rule)
     * @link https://en.wikipedia.org/wiki/Object_composition
     */
    public function __call($name, $arguments)
    {
        return $this->handle($name);
    }

    /**
     * Helper method to generically call methods on the $object.
     * Try to call a method on the contextual entity / domain model ONLY IF the
     * method exists.
     * any arguments passed to this method will be passed on to the method
     * called on the entity.
     *
     * @param mixed $object
     *            the object on which we want to call the method
     * @param string $method
     *            the method to call on the object
     * @return boolean|mixed
     */
    private function callCallable($object, $method)
    {
        // return true by default (because of transition guard callbacks that
        // might not exist)
        $output = true;
        // check if method exists.
        if (method_exists($object, $method)) { // && $object !== $this) { //prevent recursion
            $args = func_get_args();
            // remove $object and $method from $args so we only have the other arguments
            array_shift($args);
            array_shift($args);
            // have the methods be able to return what they like
            // but make sure the 'onCheckCanTransition method returns a boolean
            $output = call_user_func_array(array(
                    $object,
                    $method
            ), $args);
        }
        
        return $output;
    }

    /**
     * Helper method that gets the Transition object from a transition name or
     * throws an exception.
     *
     * @param string $name
     *            convention: <state_from>_to_<state_to>
     * @return Transition
     * @throws Exception
     */
    private function getTransitionWithNullCheck($name)
    {
        $transition = $this->getTransition($name);
        if ($transition === null) {
            throw new Exception(sprintf("transition not found for '%s'", $name), Exception::SM_NO_TRANSITION_FOUND);
        }
        return $transition;
    }

    public function toString()
    {
        return get_class($this) . ": [" . $this->getContext()->getId(true) . "]";
    }

    public function __toString()
    {
        return $this->toString();
    }
    
    // ###################### HOOK METHODS FOR THE TEMPLATE METHODS #######################
    // http://c2.com/cgi/wiki?HookMethod
    // https://en.wikipedia.org/wiki/Template_method_pattern
    

    /**
     * hook method.
     * override in subclass if necessary.
     * Before a transition is checked to be possible, you can add domain
     * specific logic here by overriding this method in a subclass.
     * In an overriden implementation of this method you can stop the transition
     * by returning false from this method.
     *
     * @param Transition $transition            
     * @param string $event
     *            an event name if the transition was triggered by an event.
     * @return boolean if false, the transition and it's associated logic will
     *         not take place
     */
    protected function _onCheckCanTransition(Transition $transition, $event = null)
    {
        // eg: dispatch an event and see if it is rejected by a listener
        return true;
    }

    /**
     * hook method.
     * override in subclass if necessary.
     * Called before each transition will run and execute the associated
     * transition logic.
     * A hook to implement in subclasses if necessary, to do stuff such as
     * dispatching events, locking an entity, logging, begin transaction via
     * persistence
     * layer etc.
     *
     * @param Transition $transition            
     * @param string $event            
     */
    protected function _onExitState(Transition $transition, $event = null)
    {}

    /**
     * hook method.
     * override in subclass if necessary.
     *
     * @param Transition $transition            
     * @param string $event            
     */
    protected function _onTransition(Transition $transition, $event = null)
    {
        // TODO
        // $this->getDispatcher()->dispatch('transition', $transition);
    }

    /**
     * hook method.
     * override in subclass if necessary.
     * Called after each transition has run and has executed the associated
     * transition logic.
     * a hook to implement in subclasses if necessary, to do stuff such as
     * dispatching events, unlocking an entity, logging, cleanup, commit
     * transaction via
     * the persistence layer etc.
     *
     * @param Transition $transition            
     * @param string $event            
     */
    protected function _onEnterState(Transition $transition, $event = null)
    {}

    /**
     * process a string to a name that is a valid method name.
     * This allows you to transform 'event' strings to strings that conform
     * to a format you wish to use
     *
     * @param string $name            
     * @return string
     */
    protected function toValidMethodName($name)
    {
        // override to manipulate the return value
        return $name;
    }
}
