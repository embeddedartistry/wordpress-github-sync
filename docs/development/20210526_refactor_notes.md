These notes are preserved from the original research/refactoring effort for posterity.

## Plugin Tweaks

Likely need to update this function to make sure we can recreate the proper path:

```
/**
     * Deletes a post from the database based on its GitHub path.
     *
     * @param string $path Path of Post to delete.
     *
     * @return string|WP_Error
     */
    public function delete_post_by_path( $path ) {
        $query = new WP_Query( array(
            'meta_key'       => '_wghs_github_path',
            'meta_value'     => $path,
            'meta_compare'   => '=',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );

        $post_id = $query->get_posts();
        $post_id = array_pop( $post_id );

        if ( ! $post_id ) {
            $parts     = explode( '/', $path );
            $filename  = array_pop( $parts );
            $directory = $parts ? array_shift( $parts ) : '';

            if ( false !== strpos( $directory, 'post' ) ) {
                $post_id = get_post_id_by_filename( $filename, '/([0-9]{4})-([0-9]{2})-([0-9]{2})-(.*)\.md/' );
            }

            if ( ! $post_id ) {
                $post_id = get_post_id_by_filename( $filename, '/(.*)\.md/' );
            }
        }

        if ( ! $post_id ) {
            return new WP_Error(
                'path_not_found',
                sprintf(
                    __( 'Post not found for path %s.', 'wordpress-github-sync' ),
                    $path
                )
            );
        }

        $result = wp_delete_post( $post_id );

        // If deleting fails...
        if ( false === $result ) {
            $post = get_post( $post_id );

            // ...and the post both exists and isn't in the trash...
            if ( $post && 'trash' !== $post->post_status ) {
                // ... then something went wrong.
                return new WP_Error(
                    'db_error',
                    sprintf(
                        __( 'Failed to delete post ID %d.', 'wordpress-github-sync' ),
                        $post_id
                    )
                );
            }
        }

        return sprintf(
            __( 'Successfully deleted post ID %d.', 'wordpress-github-sync' ),
            $post_id
        );
    }
```

Likely need to update this for paths

```
    /**
     * Retrieves or calculates the proper GitHub path for a given post
     *
     * Returns (string) the path relative to repo root
     */
    public function github_path() {
        $path = $this->github_directory() . $this->github_filename();

        return $path;
    }

    /**
     * Get GitHub directory based on post
     *
     * @return string
     */
    public function github_directory() {
        if ( 'publish' !== $this->status() ) {
            return apply_filters( 'wghs_directory_unpublished', '_drafts/', $this );
        }

        $name = '';

        switch ( $this->type() ) {
            case 'post':
                $name = 'posts';
                break;
            case 'page':
                $name = 'pages';
                break;
            case 'glossary':
                $name = 'glossary'
                break;
            default:
                $obj = get_post_type_object( $this->type() );

                if ( $obj ) {
                    $name = strtolower( $obj->labels->name );
                }

                if ( ! $name ) {
                    $name = '';
                }
        }

        if ( $name ) {
            $name = '_' . $name . '/';
        }

        return apply_filters( 'wghs_directory_published', $name, $this );
    }
```

This is an example of going from the other direction. We need to expand this to make sre all of the items are covered properly. Likely need to see where this is called in order to add additional logic for handling courses, where we have moduels + lessons in a hierarchy that need to get handled...

```
    /**
     * Retrieve post type directory from blob path.
     *
     * @param string $path Path string.
     *
     * @return string
     */
    public function get_directory_from_path( $path ) {
        $directory = explode( '/', $path );
        $directory = count( $directory ) > 0 ? $directory[0] : '';

        return $directory;
    }

```

NEED to output to a subdirectory? "website/"

```
/**
     * Build GitHub filename based on post
     */
    public function github_filename() {
        if ( 'post' === $this->type() ) {
            $filename = get_the_time( 'Y/m/d/', $this->id ) . $this->get_name() . '.md';
        } else {
            $filename = $this->get_name() . '.md';
        }

        return apply_filters( 'wghs_filename', $filename, $this );
    }
```

Separated by days is annoying, so do months?

```
    /**
     * Build GitHub filename based on post
     */
    public function github_filename() {
        if ( 'post' === $this->type() ) {
            $filename = get_the_time( 'Y/m/', $this->id ) . $this->get_name() . '/' $this->get_name() . '.md';
        } else {
            $filename = $this->get_name() . '/' $this->get_name() . '.md';
        }

        return apply_filters( 'wghs_filename', $filename, $this );
    }
```

Let's stick with years, and then we'll put each article and page into its own folder:

```
    /**
     * Build GitHub filename based on post
     */
    public function github_filename() {
        if ( 'post' === $this->type() ) {
            $filename = get_the_time( 'Y/', $this->id ) . $this->get_name() . '/' $this->get_name() . '.html';
        } else {
            $filename = $this->get_name() . '/' $this->get_name() . '.md';
        }

        return apply_filters( 'wghs_filename', $filename, $this );
    }
```

We need the .md extension for things to work out properly with pandoc...

pandoc -o output.html were-back-in-action-and-heres-our-plan-for-2021.md --ascii

-ascii is needed because otherwise we get a weird jumbled mix of charaters

post types to add in a private repo
fieldatlas
newsletters

newsletters:
`year/month/<filename>.md`

fieldatlas:
`<filename>/<filename>.md `

glossary entries? fieldmanual-terms
no: glossary

do we rename the folder somehow?
test-website-sync/_cm tooltip glossary ecommerce/
to glossary/
    
```
    /**
     * Currently whitelisted post types.
     *
     * @var array
     */
    protected $whitelisted_post_types = array( 'post', 'page' );
```
also: lessons.... courses... modules??
need to figure out hierarchy for courses
https://github.com/Automattic/sensei/blob/master/includes/class-sensei-posttypes.php

post types = course, lesson, quiz, question, multiple_question
is module one?

likely don't want to track quiz, question, multiple_question after reviewing the data


can we update the metadata for lessons to identify module?


This function can be modified for directory naming :)
    
```
        public function github_directory() {
        if ( 'publish' !== $this->status() ) {
            return apply_filters( 'wghs_directory_unpublished', '_drafts/', $this );
        }

        $name = '';

        switch ( $this->type() ) {
            case 'post':
                $name = 'posts';
                break;
            case 'page':
                $name = 'pages';
                break;
            default:
                $obj = get_post_type_object( $this->type() );

                if ( $obj ) {
                    $name = strtolower( $obj->labels->name );
                }

                if ( ! $name ) {
                    $name = '';
                }
        }
```
    
Can we remove the annoying underscore preceding names?

HERE'S WHAT WE WANT!
    - Export course pages
    - Export lesson pages, and for each lesson:
        - get the course ID, convert that to a course name somehow (func?)
        - Get the module name
        - create the path to be course/module/lesson-name
        - Do we get ordering information and then add a number on top of that? (get the module order, etc.)
        - Likely, seems i need to go in the inverse direction too?
    
    
For courses: check these functions:
    - sensei_have_modules - checks if the current course has modules
    - sensei_module_has_lessons
    -sensei_quiz_has_questions
    
- sensei_course_archive_meta (better for yaml generation??)
    
AHA: https://docs.woocommerce.com/sensei-apidocs/class-Sensei_Course.html

course_lessons( integer $course_id = 0, string $post_status = 'publish', string $fields = 'all'  )
course_lessons function.
Parameters
$course_id
(default: 0)
$post_status
(default: 'publish')
$fields
(default: 'all'). WP only allows 3 types, but we will limit it to only 'ids' or 'all'
Returns
array{
type WP_Post } $posts_array
    
https://docs.woocommerce.com/sensei-apidocs/index.html
    
    
https://docs.woocommerce.com/sensei-apidocs/class-Sensei_Core_Modules.html
    
get_course_module_order?
get_lesson_module: get module for lesson
get_course_modules: get ordered array of all modules in course
        Do we prepend name with xx-name? (00-getting started, 01-xxx, 02-yyy)
get_lessons - get all lessons for the given module ID
    
    
LESSON:
    get_course_id( integer $lesson_id )
Returns the course for a given lesson
Parameters
$lesson_id
Returns
integer|boolean
$course_id or bool when nothing is found.
Since
1.7.4
https://docs.woocommerce.com/sensei-apidocs/class-Sensei_Lesson.html
    
## Notes

· Automate updates of articles using GitHub

o This plugin might be suitable: [https://github.com/mAAdhaTTah/wordpress-github-sync](https://github.com/mAAdhaTTah/wordpress-github-sync)

§ Might also be dead… “not tested with the last three version sof wordpress”, and last release in 2017. But maybe it will also give us some clues!

§ Would be good to try out on the staging site either way

§ [https://keydigital.ca/backup-wordpress-posts-pages-to-github/](https://keydigital.ca/backup-wordpress-posts-pages-to-github/)

§ **4/14/21: I tested this out, did not get it working… might be a useful starting point**

§ Forked and improved version:

· [https://wordpress.org/plugins/writing-on-github/](https://wordpress.org/plugins/writing-on-github/)

· [https://github.com/litefeel/writing-on-github/](https://github.com/litefeel/writing-on-github/)

· **THIS ONE SEEMED TO WORK!!! At least enough to export + import**

· **Modificaitons might be needed to be able to sync courses, newsletters, and field atlas content (shouldn’t be public)**

· **Maybe we can hard-code these?**

o Could use this tool and the process outlined in the article

§ https://github.com/samuel-emrys/gwbridge

§ https://www.samueldowling.com/2020/06/08/how-to-use-a-continuous-integration-and-deployment-ci-cd-pipeline-in-your-blogging-workflow-with-gwbridge/

o Maybe we need to enable webhooks: [https://wordpress.org/plugins/wp-webhooks/](https://wordpress.org/plugins/wp-webhooks/) (we will possibly need the pro version)

o There is also this process: [https://www.sitepoint.com/git-and-wordpress-how-to-auto-update-posts-with-pull-requests/](https://www.sitepoint.com/git-and-wordpress-how-to-auto-update-posts-with-pull-requests/)

o Possibly useful? [https://github.com/versionpress/versionpress](https://github.com/versionpress/versionpress)
