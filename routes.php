<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

use Twig\Environment;
use Slim\Routing\RouteCollectorProxy;
use Twig\Loader\FilesystemLoader;

$loader = new FilesystemLoader('templates');
$view = new Environment($loader);

$pdo = new PDO('sqlite:db\rezervacni_system.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$date = date('d-m-Y');
$timestamp = strtotime($date);

$app->get('/login', function (Request $request, Response $response) use ($view) {
    $body = $view->render('login.twig');
    $response->getBody()->write($body);
    return $response;
})->setName('login');


$app->post('/login', function (Request $request,Response $response) use ($pdo, $view) {
    $formData = $request->getParsedBody(); // [username ->"abcd", password->123]
    //var_dump($formData);

    $passwd_hash = strtoupper(hash('sha256', $formData['password']));

    $stmt = $pdo->prepare('SELECT * FROM users WHERE lower(username) = lower(:usr) AND password = :pswd');
    $stmt->bindParam(':usr', $formData['username']);
    $stmt->bindParam(':pswd',$passwd_hash);
    $stmt->execute();
    $loggedUser = $stmt->fetch();
    if(!empty($loggedUser)){
        $_SESSION['loggedUser'] = $loggedUser;//first_name
        $_SESSION['loggedUser']['message'] = "";
        return $response->withHeader('Location','auth/user/' . $formData['username']);
    }
    else {
        $body = $view->render('login.twig');
        $response->getBody()->write($body);
        return $response;
    }
});


$app->group('/auth', function(RouteCollectorProxy $group) use ($pdo, $view, $app) {
    $group->get('/user/{username}', function (Request $request, Response $response) use ($pdo, $view) {
        try {
            $sql = $pdo->prepare('SELECT user_type FROM users WHERE username = (:usr)');
            $sql->bindParam(':usr',$_SESSION['username']);
            $sql->execute();
            $opTime_query = $pdo->prepare('SELECT * FROM opening_time');
            $opTime_query->execute();
            $optime_time_result = $opTime_query->fetchAll();
            $today_optime = $pdo->prepare('SELECT * FROM opening_time WHERE optime_date=:date');
            $today_optime->bindParam(':date', $timestamp);
            $today_optime->execute();
            $today_optime_result = $today_optime->fetch();
            $vacs = $pdo->prepare('SELECT * FROM vacation');
            $vacs->execute();
            $vacs_res =  $vacs->fetchAll();
            switch($_SESSION['loggedUser']['user_type']){
                case 'student': {
                    $sql = $pdo->prepare('SELECT * FROM appointment WHERE student_id= :user_id');
                    $sql->bindParam(':user_id',$_SESSION['loggedUser']['id']);
                    $sql->execute();
                    $result = $sql->fetchAll();
                    $body = $view->render('profile_stud.twig',['first_name'=> $_SESSION['loggedUser']['username'],
                                                'username' => $_SESSION['loggedUser']['username'],
                                                'appointment' => $result,
                                                'optime_today' => $today_optime_result,
                                                'message' => $_SESSION['loggedUser']['message']]);
                    $response->getBody()->write($body);
                    $_SESSION['loggedUser']['message'] = "";
                    return $response;
                }
                case 'refer':
                    $sql = $pdo->prepare('SELECT * FROM appointment WHERE employee_id= :user_id AND object_of_study=:obj');
                    $sql->bindParam(':user_id',$_SESSION['loggedUser']['id']);
                    $sql->bindParam(':obj',$_SESSION['loggedUser']['object_of_study']);
                    $sql->execute();
                    $result = $sql->fetchAll();
                    $body = $view->render('profile_refer.twig',['first_name' => $_SESSION['loggedUser']['username'],
                                                                      'appointment' => $result,
                                                                      'username' => $_SESSION['loggedUser']['username']]);
                    $response->getBody()->write($body);
                    return $response;
                case 'vedouci':
                    $sql = $pdo->prepare('SELECT * FROM appointment');
                    $sql->execute();
                    $result = $sql->fetchAll();
                    $body = $view->render('profile_ved.twig',['first_name' => $_SESSION['loggedUser']['username'],
                                                                    'username' => $_SESSION['loggedUser']['username'],
                                                                    'appointment' => $result,
                                                                    'opening_time' => $optime_time_result,
                                                                    'time_from' => $today_optime_result['time_from'],
                                                                    'time_to' => $today_optime_result['time_to'],
                                                                    'vacation' => $vacs_res]);
                    $response->getBody()->write($body);
                    return $response;
                }
        } catch (Exception $e) {

        }
        return $response;
    })->setName('auth');

    $group->get('/user/{username}/logout', function (Request $request, Response $response) use ($group) {
        session_destroy();
        return $response->withStatus(302)->withHeader('Location','../../../login');
    })->setName('logout');
/*(SELECT * FROM vacation WHERE vacation.employee_id=:empl_id AND :time BETWEEN
                                                            time_from AND time_to AND :date BETWEEN date_from AND date_to)*/
    $group->post('/user/{username}/new_apt', function (Request $request, Response $response) use ($pdo, $group) {
        $formData = $request->getParsedBody();
        $apt_time = strtotime($formData['']);
        $sql_available_employees = $pdo->prepare('SELECT id FROM users WHERE user_type=:usr_type AND object_of_study=:obj 
                                                        AND EXISTS (SELECT * FROM opening_time WHERE optime_date=:date AND :time BETWEEN time_from AND time_to)
                                                        EXCEPT SELECT employee_id FROM appointment 
                                                        WHERE aptdate=:date AND apttime=:time
                                                        ');
        $usr_type = "refer";
        $obj = $_SESSION['loggedUser']['object_of_study'];
        $sql_available_employees->bindParam(':usr_type', $usr_type);
        $sql_available_employees->bindParam(':obj', $obj);
        $sql_available_employees->bindParam(':date', $formData['aptdate']);
        $sql_available_employees->bindParam(':time', $formData['apttime']);
        $sql_available_employees->execute();
        $request = $sql_available_employees->fetch();
        if($request['id']!=null) {
            $sql = $pdo->prepare('INSERT INTO appointment(student_id, aptdate, apttime, employee_id, object_of_study) VALUES(:stud_id, :date, :time,:empl_id,:obj)');
            $sql->bindParam(':stud_id', $_SESSION['loggedUser']['id']);
            $sql->bindParam(':date', $formData['aptdate']);
            $sql->bindParam(':time', $formData['apttime']);
            $sql->bindParam(':empl_id', $request['id']);
            $sql->bindParam(':obj', $obj);
            $sql->execute();
        }
        else{
            $_SESSION['loggedUser']['message'] = "Ve zvolenem čase nejsou k dispozici žádné pracovníky nebo dosud není nastavena otevírací doba";
        }
        return $response->withHeader('Location','/auth/user/' . $_SESSION['loggedUser']['username']);
    })->setName('new_apt');

    $group->post('/user/{username}/new_optime', function (Request $request, Response $response) use ($pdo, $group) {
        $formData = $request->getParsedBody();
        $apt_time = strtotime($formData['datum']);
        $sql = $pdo->prepare('INSERT INTO opening_time(optime_date, time_from,time_to) VALUES(:date, :from, :to)');
        $sql->bindParam(':date', $formData['optime_date']);
        $sql->bindParam(':from', $formData['time_from']);
        $sql->bindParam(':to', $formData['time_to']);
        $sql->execute();
        return $response->withHeader('Location','/auth/user/' . $_SESSION['loggedUser']['username']);
    })->setName('new_optime');

    $group->get('/user/{username}/delete/{id}', function (Request $request, Response $response) use ($app, $pdo, $group) {
        try {
            $apt_id = $request->getAttribute('id');
            $stmt = $pdo->prepare('DELETE FROM appointment WHERE id = :id_apt');
            $stmt->bindParam(':id_apt', $apt_id);
            $stmt->execute();
        } catch (Exception $e) {
            $this->logger->error($e);
        }
        return $response->withHeader('Location','/auth/user/' . $_SESSION['loggedUser']['username']);
    })->setName('delete_apt');

    $group->get('/user/{username}/accept/{id}', function (Request $request, Response $response) use ($app, $pdo, $group) {
        try {
            $apt_id = $request->getAttribute('id');
            $stmt = $pdo->prepare('UPDATE appointment SET accepted=1 WHERE id = :id_apt');
            $stmt->bindParam(':id_apt', $apt_id);
            $stmt->execute();
        } catch (Exception $e) {
            $this->logger->error($e);
        }
        return $response->withHeader('Location','/auth/user/' . $_SESSION['loggedUser']['username']);
    })->setName('accept_apt');
    $group->post('/user/{username}/attach_empl', function (Request $request, Response $response) use ($app, $pdo, $group) {
        try {
            $formData = $request->getParsedBody();
            $stmt = $pdo->prepare('UPDATE users SET object_of_study=:obj WHERE first_name= :fn AND last_name=:ln');
            $stmt->bindParam(':fn', $formData['first_name']);
            $stmt->bindParam(':ln', $formData['last_name']);
            $stmt->bindParam(':obj', $formData['object_of_study']);
            $stmt->execute();
        } catch (Exception $e) {
            $this->logger->error($e);
        }
        return $response->withHeader('Location','/auth/user/' . $_SESSION['loggedUser']['username']);
    })->setName('attach_empl');
    $group->post('/user/{username}/new_vacation', function (Request $request, Response $response) use ($pdo, $group) {
        $formData = $request->getParsedBody();
        $apt_time = strtotime($formData['datum']);
        $sql = $pdo->prepare('INSERT INTO vacation(date_from, time_from, date_to, time_to, employee_id) VALUES(:date_from, :time_from,:date_to, :time_to, :empl_id )');
        $sql->bindParam(':date_from', $formData['date_from']);
        $sql->bindParam(':time_from', $formData['time_from']);
        $sql->bindParam(':date_to', $formData['date_to']);
        $sql->bindParam(':time_to', $formData['time_to']);
        $sql->bindParam(':empl_id', $_SESSION['loggedUser']['id']);
        $sql->execute();
      /*  $sql = $pdo->prepare('DELETE FROM appointment WHERE (aptdate,apttime)
                          BETWEEN (SELECT date_from,time_from FROM vacation WHERE employee_id=:empl_id) AND 
                            (SELECT date_to,time_to FROM vacation WHERE employee_id=:empl_id)');
        $sql->bindParam(':empl_id', $_SESSION['loggedUser']['id']);*/
        return $response->withHeader('Location','/auth/user/' . $_SESSION['loggedUser']['username']);
    })->setName('new_vac');
});





