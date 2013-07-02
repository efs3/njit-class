<?php
/**
 * @file
 * Allocator Class
 *
 * @package groupgrade
 */
namespace Drupal\ClassLearning\Workflow;

use Drupal\ClassLearning\Exception as AllocatorException,
  Drupal\ClassLearning\Models\WorkflowTask;

/**
 * User Allocator
 *
 * Used to assign a pool of users to specific roles inside of a work flow.
 * See previous work {@link http://web.njit.edu/~mt85/UsersAlg.php}
 * 
 * @license MIT
 */
class Allocator {
  /**
   * Storage of the users
   * 
   * @var array
   */
  protected $user = [];

  /**
   * @ignore
   */
  protected $instructor;
  
  /**
   * Workflow Storage
   *
   * To access, use `getWorkflows()` or `addWorkflow()`
   * 
   * @var array
   */
  protected $workflows = [];

  /**
   * Roles Storage
   *
   * To access, use `getRoles()`
   * 
   * @var array
   */
  protected $roles = [];

  /**
   * @ignore
   */
  protected $roles_rules = [];

  /**
   * Temporary storage for users being added to roles
   *
   * @access private
   * @var array
   */
  protected $roles_queue = [];

  /**
   * Use to track the number of runs the algorithm has run
   * No beneficial use
   * 
   * @var integer
   */
  public $runCount = 0;

  /**
   * A two-dimensional array of storage for keeping the storage
   * of the task instance id's relative to the workflow->role ID
   *
   * The array looks like this:
   * 
   * ```
   * [
   *   'workflow_id' => [
   *     'internal role id' => 'task instance id',
   *     'internal role id' => 'task instance id',
   *     'internal role id' => 'task instance id'    
   *   ],
   *   'workflow_id' => [
   *     'internal role id' => 'task instance id',
   *     'internal role id' => 'task instance id'
   *   ],
   *   ...
   * ]
   * @global array
   */
  protected $taskInstanceStorage = [];

  /**
   * Construct the Allocator Algorithm
   *
   * @todo Restructure how we store users
   * @param SectionUsers Users from a section
   */
  public function __construct($users)
  {
    if (count($users) > 0) : foreach ($users as $user) :
      $this->users[$user->user_id] = [
        'role' => $user->su_role,
      ];
    endforeach; endif;
  }

  /**
   * Grunt work to assign users
   *
   * It'd be best to run `assignmentRun()` as that method automatically detects errors
   * and fixes them. This is a helper processor.
   * 
   * @return void
   */
  public function runAssignment()
  {
    if (count($this->roles) == 0)
      throw new AllocatorException('Roles are not defined for allocation.');

    // Reset it
    $this->resetWorkflows();

    if (count($this->workflows) == 0)
      throw new AllocatorException('No workflows to allocate to.');
    
    // Now let's find the assignes
    foreach($this->roles as $role_id => $role_data) :
      // Get just their student IDs
      $this->roles_queue[$role_id] = array_keys($this->users);

      // Let's keep this very random
      shuffle($this->roles_queue[$role_id]);
    endforeach;

    // Go though the workflows
    foreach($this->workflows as $workflow_id => $workflow)
    {
      // Loop though each role inside of the workflow
      // 
      // Loop though all the users in the queue
      // 
      // Can join: assign and remove from queue
      // Can't join: point to next user in queue
      foreach($workflow as $role_id => $ignore) :
        // Start from the beginning of the queue
        foreach($this->roles_queue[$role_id] as $queue_id => $user_id) :
          // They're not a match -- skip to the next user in queue
          if ($this->canEnterWorkflow($user_id, $this->workflows[$workflow_id]))
          {
            $this->workflows[$workflow_id][$role_id] = $user_id;

            // Remove this student from the queue
            unset($this->roles_queue[$role_id][$queue_id]);
            break;
          }
        endforeach;
      endforeach;
    }
  }

  /**
   * Identify if a user can enter a specific workflow
   *
   * Helper function to see if a user is already in a
   * workflow (cannot join then).
   * 
   * @param int
   * @param array
   * @return bool
   */
  protected function canEnterWorkflow($user_id, $workflow)
  {
    foreach($workflow as $role => $assigne)
    {
      if ($assigne !== NULL AND (int) $assigne === (int) $user_id)
        return FALSE;
    }
    return TRUE;
  }

  /**
   * Does a workflow contain a duplicate error?
   *
   * @return bool
   */
  public function contains_error($workflow)
  {
    if ($workflow !== array_unique($workflow, SORT_NUMERIC))
      return TRUE;
    
    // Check if it contains unassigned users
    foreach ($workflow as $role => $user) :
      if ($user === NULL) return TRUE;
    endforeach;

    return FALSE;
  }

  /**
   * See if an array of workflows contains any errors
   *
   * @return bool
   */
  public function contains_errors($workflows)
  {
    foreach($workflows as $workflow) :
      if ($this->contains_error($workflow) ) return TRUE;
    endforeach;

    return FALSE;
  }

  /**
   * Empty Workflow
   * The default values for a workflow
   *
   * @return array
   */
  public function emptyWorkflow($workflow_id)
  {
    // Let's get the tasks for this workflow
    $tasks = WorkflowTask::where('workflow_id', '=', $workflow_id)
      ->get();

    // Setup the instance storage for this workflow
    $this->taskInstanceStorage[$workflow_id] = $usedInstances = [];

    $i = [];
    foreach($this->roles as $role_id => $role_data) :
      // Determine which task instance this is
      $taskInstanceId = 0;
      foreach ($tasks as $task) :
        // It's a match
        if ($task->type == $role_data['name'] AND ! in_array($task->task_id, $usedInstances))
        {
          $taskInstanceId = $task->task_id;
          $usedInstances[] = $task->task_id;
          break;
        }
      endforeach;

      if ($taskInstanceId == 0)
        throw new AllocatorException(
          sprintf('Unknown task instance id to assign for role %s of workflow %d', $role_data['name'], $workflow_id)
        );
      else
        $this->taskInstanceStorage[$workflow_id][$role_id] = $taskInstanceId;

      // Add this to the tempory storage
      $i[$role_id] = NULL;
    endforeach;

    return $i;
  }

  /**
   * Reset all the workflows
   *
   * @access protected
   */
  protected function resetWorkflows()
  {
    // Clear the instance storage
    $this->taskInstanceStorage = [];

    foreach ($this->workflows as $workflow_id => $workflow) 
      $this->workflows[$workflow_id] = $this->emptyWorkflow($workflow_id);
  }

  /**
   * Add a user role (problem creator, solver, etc)
   *
   * @param string
   * @param string
   */
  public function createRole($name, $rules = [])
  {
    $this->roles[] = [
      'name' => $name,
      'rules' => $rules,
    ];
  }

  /**
   * Get the Workflows
   *
   * @return array
   */
  public function getWorkflows()
  {
    return $this->workflows;
  }

  /**
   * Get a Workflow
   *
   * @param integer
   * @return array|void
   */
  public function getWorkflow($workflow_id)
  {
    return (isset($this->workflows[$workflow_id])) ? $this->workflows[$workflow_id] : NULL;
  }

  /**
   * Get the task instance storage
   *
   * This is the associative IDs to associate the internal role ID inside of
   * the `getWorkflow()` data to the task instance ID from the workflow.
   * 
   * @return array
   */
  public function getTaskInstanceStorage()
  {
    return $this->taskInstanceStorage;
  }

  /**
   * Add a workflow
   *
   * This should be called **after** registering all the roles for the allocation
   * 
   * @param int
   */
  public function addWorkflow($workflow_id)
  {
    $this->workflows[$workflow_id] = NULL;
  }

  /**
   * Get the Roles
   *
   * @return array
   */
  public function getRoles()
  {
    return $this->roles;
  }

  /**
   * Inteligently run the sorting algorithm
   *
   * We run it for however much $maxRuns is set to to ensure we get the
   * least amount of errors.
   *
   * @todo If cannot find one w/o errors, return one with least
   * @param integer Max runs
   * @return object Object of Allocator class
   */
  public function assignmentRun($maxRuns = 20)
  {
    $index = [];
    $errorIndex = [];
    $runCount = 0;

    for ($i = 0; $i < $maxRuns; $i++) :
      $this->runCount++;

      $this->runAssignment();

      $hasErrors = $this->contains_errors($this->getWorkflows());

      if (! $hasErrors)
        return $this;
    endfor;

    return $this;
  }

  /**
   * Dump the details of the allocation
   *
   * Used to debug the allocation
   * 
   * @return void
   */
  public function dump()
  {
    ?>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.1/jquery.min.js"></script>
<script type="text/javascript">
  $(document).ready(function()
{
  console.log('ready');
  $('table td').click(function() {
    name = $(this).text();
    
    // Remove the previous ones
    $('table td[bgcolor="green"]').removeAttr('bgcolor')

    $('table td').each(function()
    {
      if ($(this).text() == name) {
        $(this).attr('bgcolor', 'green');
      }
    });
  });
});
</script>
<table width="100%" border="1">
  <thead>
    <tr>
      <?php foreach($this->roles as $role_id =>$role_data) : ?>
        <th><?php echo $role_data['name']; ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach($this->workflows as $user_id => $workflow) : ?>
      <tr <?php if ($this->contains_error($workflow)) echo 'bgcolor="orange"'; ?>>
        <?php foreach($workflow as $role => $assigne) :
          if ($assigne === NULL) :
            ?><td bgcolor="red">NONE</td><?php
          else :
            $user = user_load($assigne);
            ?><td><?php echo $user->name; ?></td><?php
          endif;
        endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<!-- Now Show a user's membership table -->
<p>&nbsp;</p>

<table width="100%" border="1">
  <thead>
    <tr>
      <th>Student</th>

      <?php foreach($this->roles as $role_id => $role_data) : ?>
        <th>is <?php echo $role_data['name']; ?>?</th>
      <?php endforeach; ?>
    </tr>
  </thead>

  <tbody>
    <?php foreach($this->users as $user_id => $student) : ?>
    <tr>
      <td><?php echo user_load($user_id)->name; ?></td>

    <?php foreach($this->roles as $role_id => $role_data) : $found = false; ?>
      <?php
      foreach($this->workflows as $workflow) :
        if ($workflow[$role_id] !== NULL AND $workflow[$role_id] == $user_id) :
          ?><td bgcolor="blue">YES</td><?php
        $found = true;
        endif;
      endforeach;
      if (! $found) : ?>
          <td bgcolor="red">NO</td>
        <?php endif;
    endforeach; endforeach; ?>
  </tr>
  </tbody>
</table>

<p><strong>Total Students:</strong> <?php echo count($this->users); ?></p>
<p><strong>Total Runs:</strong> <?php echo $this->runCount; ?></p>
<pre>
<?php echo print_r($this->workflows); ?>
</pre>
<?php
  }
}
