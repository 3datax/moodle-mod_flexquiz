# Flex Quiz

**Flex Quiz** is an activity module for Moodle. It creates quizzes in an automated manner, allowing teachers to test the knowledge of their students in a dynamically adapting way without the need for manual quiz creation.

## Supported Moodle versions

* Moodle 3.8+

## Plugin setup

### Admin settings

After installing the **Flex Quiz** plugin, you can change its settings in the administration menu by going to "Plugins / Activities / Flex Quiz". There you can specify if you want to allow the AI powered mode for your **Flex Quizzes**. Note that you can still change if AI should be used for each **Flex Quiz** activity individually. In the plugin settings you just globally specify your AI API credentials. For more information on basic mode vs. AI mode, refer to the **Modes** section futher below. If you are just getting started with this plugin you may experiment with basic mode first, before trying out AI mode.

### Server tasks

If at some point quiz creation should fail or is not possible, the "Flexquiz children cleanup" task of this plugin will resolve any issues the next time it is run. You can access it via "Scheduled tasks" in the "Server / Tasks" part of the administration menu. It is recommended to run it once per hour with the default minutes setting "R".

## How to use and how it works

### Create activity

Once installed and set up, you can create **Flex Quiz** activities inside your Moodle courses. In the creation form you have following plugin specific fields:

* **Flex Quiz name**: The name of this activity.
* **Parent quiz**: Here you need to select an existing standard moodle quiz in your course. This parent quiz serves as the question pool - i.e. you need to select a quiz with all the questions that should be covered and used in the dynamic learning process created by the **Flex Quiz** activity.
* **Description**: The description of this activity.
* **General**:
  * **Minimum number of questions per quiz**: During the dynamic learning process, multiple quizzes will be generated containing a subset of the question pool (from the parent quiz). With this setting you can specify a minimum number of questions per quiz.
  * **Maximum number of questions per quiz**: During the dynamic learning process, multiple quizzes will be generated containing a subset of the question pool (from the parent quiz). With this setting you can specify a maximum number of questions per quiz.
  * **Start activity at**: Specifies when the activity starts.
  * **Activity ends at**: Specifies when the activity ends. Can be enabled/disabled via the checkbox. If disabled, the activity will run for an infinite duration.
  * **Quiz time limit**: The time limit for each quiz.
* **Cycles**:
  * **Maximum number of quizzes per cycle**: Defines the maximum number of quizzes per cycle for each student. If this number is reached by a student, no more quizzes will be generated for him/her in this cycle.
  * **Cycle duration**: How long each cycle lasts. The first cycle starts immediately on the activity start ("**Start activity at**").
  * **Mandatory pause between quizzes**: How long a student must wait after completing a quiz until he/she can start the next one.
  * **Consecutive correct answers required**: The number of consecutive correct answers required to consider a question mastered. Only when the given number is reached will a question not reappear before the next cycle starts.
  * **Roundup cycle (Advanced)**: Selecting this option will cause the last cycle to be handled differently: The "**Consecutive correct answers required**" setting will be set to 1 for this one cycle. Use this option if you want your last cycle to act like a kind of roundup quiz for your students.
* **AI Options**:
  * **Use AI**: If set to "Yes", AI mode will be used for this activity (see **Modes** section futher below).

### Students doing quizzes

Once a **Flex Quiz** activity has been created, each enrolled student of the course will start getting quizzes that he/she solves over the span of the activity's duration. This also works retroactive, i.e. if a student gets enrolled after a **Flex Quiz** activity has been created, he/she will also be enlisted in the activity and starts getting quizzes as well. However, this will not happen immediately but only on the next scheduled task run (See **Plugin setup > Tasks** section above).

Each student always has up to one active quiz per **Flex Quiz** activity created inside a course. On completing the quiz, a new one will start until there are no more eligible questions for this student. Eligibility of questions depends on a student’s past performance and on the active cycle - in general, questions where the student performed worse will appear more often than questions where the student performed better.

Each question which is part of the question pool will be used at least once per cycle for each student. Completed quizzes will be removed automatically, there is no need to clean them up manually. Once the flex quiz has reached its end date, no more new quizzes will be created.

### Grading

Grades of the **Flex Quiz** activity will be updated with each quiz completion (There are no grades for individual quizzes spawned by a **Flex Quiz** activity though, only for the whole activity). You can see the grades of each student in the activity's overview at any time. Students can see their own grade when they view the activity's overview as well.

## Modes

A **Flex Quiz** supports the following two modes:
* AI mode (**Use AI** set to "Yes")
* Basic mode (**Use AI** set to "No")

### AI mode

The questions used in each quiz are determined by an AI providing smart individual selection based on the skills of each student. This mode requires an AI connection to be set up and correctly configured via the plugin admin settings.

The get information on the AI setup for this plugin and how to get an API-key and the API-url, please contact us at [danube.ai/contact](https://danube.ai/contact)

### Basic mode

Selection is done in a much less sophisticated way, albeit there are mechanisms in place to ensure that each student has to answer every question that is part of the question pool at least once per cycle. Questions which have been answered correctly will not reappear within the same cycle unless the "Minimum number of questions" setting enforces it.

## Supported plugins

The **Flex Quiz** does not have any other plugin dependencies, but it supports using the [Flexible Sections](https://moodle.org/plugins/format_flexsections) format to keep the quizzes generated by the activity organized inside your courses. If a course has the Flexible Sections format enabled, for each **Flex Quiz** activity, a collapsible section will be created where all student quizzes are put inside. Note that this does not work retroactive, i.e. if you enable the Flexible Sections format after a **Flex Quiz** activity has already been created, for this particular **Flex Quiz** activity no section will be created.
