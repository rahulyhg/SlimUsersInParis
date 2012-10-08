<?PHP
$app = Slim::getInstance();

//Setup Twig.php namespace
$twig = $app->view()->getEnvironment();
//namespace is users
$loader = $twig->getLoader()->addPath(dirname(__FILE__) . "/templates", 'users');
/** 
    if you want to use a different template engine, be sure to chagne the calls to $app->render
    With twig, they are using the users namespace (@users)

**/
class User extends Model {
    public static $_table = 'users';    
    public static function current(){
        $app = Slim::getInstance();
        $_sCookie = $app->getEncryptedCookie('usid',false);
        $_uCookie = $app->getEncryptedCookie('uid', false);
        if( !isset($_uCookie) || $_uCookie === '' || 
            !isset($_sCookie) || $_sCookie === ''){
            return null;
        }
        $_user = Model::factory("User")
            ->where("id", $_uCookie)
            ->where("session_key", $_sCookie)
            ->find_one();
        return $_user;
    }
    public function isAdmin(){
        if($this->role === 'admin')
            return true;
        else
            return false;
    }
    public static function password($password, $salt){
        return crypt($password, '$2a$07$' . $salt . '$');
    }

    public static function salt(){
        return generate_random_string(32);
    }
    public static function login($post){
        global $baseURL;
        //find user in database
        $_user = Model::factory("User")
            ->where("username", $post->user)
            ->find_one();

        if(!$_user){
            //redirect with errors
            return null;
        }
        $encPwd = crypt($post->password, '$2a$07$' . $_user->salt . '$');

        if($encPwd != $_user->password){
             //redirect with errors
            return null;
        }

        //create a random session string.
        $_sessionKey = generate_random_string(32);
        //update user object in database
        $_user->session_key = $_sessionKey;
        $_user->save();

        $app = Slim::getInstance();     
        $app->setEncryptedCookie('uid',$_user->id, null, $baseURL);
        $app->setEncryptedCookie('usid',$_user->session_key, null,$baseURL);        
        return $_user;
    }
    //override default Paris/Idiorm as_array()
    public function as_array(){
        $arr = parent::as_array();
        unset($arr['password']);
        unset($arr['salt']);
        unset($arr['session_key']);
        return $arr;
    }
}
/**utility**/
function generate_random_string($name_length = 8) {
    $alpha_numeric = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return substr(str_shuffle($alpha_numeric), 0, $name_length);
}


/*** route middleware ***/

    /** 
        - requires a user to be logged in
        - loads currently logged in user as global $USER;
    **/
    $SUiP_requires_login = function (){
        global $baseURL,$USER;

            $app = Slim::getInstance();
            $_user = User::current();
            // Check for password in the cookie
            if( !$_user){
                session_start();
                $_SESSION['redirect'] = $_SERVER['REQUEST_URI']; 
                $app->redirect($baseURL . '/login');
            }
            $USER = $_user;
    };
    /** 
         - loads currently logged in user (if one exists) as global $USER
    **/
    $SUiP_current_user = function(){
        global $baseURL,$USER;
        $app = Slim::getInstance();
        $_user = User::current();
        $USER = $_user;

    };
    /** 
        - requires user has role = admin
    **/
    $SUiP_requires_admin = function(){
        global $USER,$baseURL;
        if(!$USER->isAdmin()){
            $app = Slim::getInstance();
            $app->redirect($baseURL);
        }
    };


/** user routes 
    /login
    /logout
    /users/:id/delete
    /users/:id
    /users/add
    /users

    **/
    
    $app->get("/logout", function() use ($app){
        global $baseURL;
        //kill my cookies
        $app->deleteCookie('uid', $baseURL);
        $app->deleteCookie('usid', $baseURL);
        $app->redirect($baseURL);

    });
    $app->map('/login', function () use ($app) {
        global $baseURL;
        // Test for Post
        if($app->request()->isPost() && sizeof($app->request()->post()) > 2)
        {
            $post = (object)$app->request()->post();
            if(isset($post->user) && isset($post->password))
            {
                $_user = User::login($post);
                if($_user){
                    //where were they going?
                    session_start();
                    if(isset($_SESSION['redirect'])){
                        $redirect = $_SESSION['redirect'];
                        unset($_SESSION['redirect']);
                        $app->redirect($redirect);
                    }else{
                        $app->redirect($baseURL);
                    }
                }else{
                    //error, redirect
                    //invalid login
                    $validation = 'Invalid login';
                }
            } 
            else
            {
                $validation = 'Invalid login';
            }
        }
        // render login
        $app->render('@users/login.html', array("validation" => isset($validation) ? $validation : null));
    })->via('GET','POST')->name('login');


    /** 
        List the current users 
        Requires
            - user is logged in & user is admin
    **/
    $app->get("/users", $SUiP_requires_login, $SUiP_requires_admin, function() use($app){
        //list our users in a table
        $users = Model::factory("User")->find_many();
        session_start();
        $validation = null;
        if(isset($_SESSION['validation'])){
            $validation = $_SESSION['validation'];
            unset($_SESSION['validation']);
        }
        $app->render("@users/list.html", array("users" => $users, "validation" => $validation));
    });

    $app->get("/users/add", $SUiP_requires_login, $SUiP_requires_admin, function() use($app){
        //list our users in a table
        $app->render("@users/add.html");
    });

    $app->post("/users/add", $SUiP_requires_login, $SUiP_requires_admin, function() use($app){
        //used for creating new users
        //handle post
        $request_post = $app->request()->post();  // <- getBody() of http request
        if($request_post["user"]["password"] != $request_post["confirmpassword"]){
            //error
            return $app->render("@users/add.html", array("validation"=>"passwords must match"));
        }
        //parse user model, save;
        $new_user = Model::factory("User")->create();
        $new_user->username = trim($request_post["user"]["username"]);
        $new_user->email = trim($request_post["user"]["email"]);
        $new_user->salt = User::salt();
        $new_user->password = User::password( trim($request_post["user"]["password"]), $new_user->salt);
        $new_user->save();

        $app->render("@users/add.html", array("validation" => "User created"));    
    });

    /* show edit form for a user */
    $app->get("/users/:id", $SUiP_requires_login, $SUiP_requires_admin, function($id) use($app){
        $user = Model::factory("User")->find_one($id);
        $app->render("@users/edit.html", array("user" => $user));
    });

    $app->post("/users/:id", $SUiP_requires_login, $SUiP_requires_admin, function($id) use($app){
        //update user
        $user = Model::factory("User")->find_one($id);
        $request_post = $app->request()->post();  // <- getBody() of http request
        if(isset($request_post["user"]["password"]) && $request_post["user"]["password"] != $request_post["confirmpassword"]){
            //error
            return $app->render("@users/edit.html", array("user" => $user, "validation"=>"passwords must match"));
        }
        $user->email = trim($request_post["user"]["email"]);
        $user->salt = User::salt();
        $user->password = User::password( trim($request_post["user"]["password"]), $user->salt);
        $user->save();
        return $app->render("@users/edit.html", array("user" => $user, "validation"=>"User updated"));
    });
    /* 
        Delete a user
        Cannot delete an admin

    */
    $app->post("/users/:id/delete(/:format)", $SUiP_requires_login, $SUiP_requires_admin, function($id, $format="html") use($app){
        global $baseURL;
        $user_to_delete = Model::factory("User")->find_one($id);
        //is $user_to_delete an admin?

        if(!$user_to_delete->isAdmin()){
            $user_to_delete->delete();
            $validation = "User successfully deleted";
            $deleted = true;
        }
        else{
            $deleted = false;
            $validation = "Cannot delete that user";
        }
        if($format == 'json')
            returnJSON(array("deleted"=>$deleted, "validation"=>$validation));
        else{
            $users = Model::factory("User")->find_many();
            session_start();
            $_SESSION['validation'] = $validation;
            $app->redirect($baseURL."/users");
        }
    });

?>