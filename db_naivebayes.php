<html>
	<head>
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
		<script type="text/javascript" src="/ITEC 4230/final/result.js"></script>
	</head>
<?php
$time_start = microtime(true);

$link = mysqli_connect('localhost','root','');
if (!$link) {
	die('Connection Error('.mysqli_connect_errno().')) '. ') '. mysqli_connect_error());
	echo "no way";
}
mysqli_select_db($link, "4230");

$db = "moviedb3";

$actors = array();
$directors = array();
$genres = array();
$prodcomp = array();
$B_intervals = array();

set_time_limit(10000);

// updates db with profit margins
$query = "select * from ".$db." where dataset=''";
if ($result = $link->query($query)) {
	while ($row = $result->fetch_assoc()) {
		$netIncome = ($row["Revenue"]-$row["Budget"]);
		$profitMargin = $netIncome/$row["Revenue"];
		if ($profitMargin >= 0.5) {
			$success = 1;
		} else {
			$success = 0;
		}
		$q = "update ".$db." set Margin=".$profitMargin.", 
		Success=".$success." where TMDBid=".$row["TMDBid"];
		if ($link->query($q) === TRUE) {
			echo "successfully updated record<br/>";
		} else {
			echo "did not update".$link->error."<br/>";
		}
	}
} else {
	echo $link->error;
}

// binning 
function get_num($function,$column) {
	global $link;
	global $db;
	$query = "select ".$function."(".$column.") as ".$function." from ".$db;
	$result = $link->query($query);
	$row = mysqli_fetch_assoc($result);
	$num = $row[$function];
	return $num;
}

// get_count
function get_count($function,$column,$where) {
	global $link;
	global $db;
	$query = "select ".$function."(".$column.") as ".$function." from ".$db." where success=".$where." and dataset=''";
	$result = $link->query($query);
	$row = mysqli_fetch_assoc($result);
	$num = $row[$function];
	return $num;
}

// calculate n to nearest desired partitions
function get_n($tcount, $rough) {
	$N = $tcount/$rough;
	for ($i=$rough+1; !is_int($N); $i++) {
		$N = $tcount/($i);
	}
	$N = $i-1;
	return $N;	
}

function get_width($max, $min, $N) {
	$width = ($max - $min)/$N;
	return $width;
}

function update_intervals($min, $max, $i) {
	global $link;
	global $db;
	$array = array();
	$query = "select tmdbid, budget from ".$db." where budget between ".$min." and ".$max;
	if ($result = $link->query($query)) {
		$link->query('START_TRANSACTION');
		while ($row = $result->fetch_assoc()) {
			$id = $row['tmdbid'];
			$int = 'interval'.$i;
			$query = "update ".$db." set B_interval = ? where tmdbid = ?";
			$stmt = $link->prepare($query);
			$stmt->bind_param('si',$int,$id);
			$stmt->execute();			
		}	
		$link->query('COMMIT');
	}
}

$tcount = get_num('count', 'tmdbid');
$max_budget = get_num('max', 'budget');
$min_budget = get_num('min', 'budget');
echo $tcount."<br/>";
echo $max_budget."<br/>";
echo $min_budget."<br/>";	

$N = get_n($tcount, 10);
echo $N."<br/>";

$width = get_width($max_budget, $min_budget, $N);
echo $width."<br/>";

// update intervals in db
$minimum = $min_budget;
$maximum = $min_budget + $width;
for ($i=0; $i<$N; $i++) {
	update_intervals($minimum, $maximum, $i);
	$minimum = $maximum;
	$maximum += $width;
}

// separate db into train and test rows
$reassign = false;
if ($reassign) {
	echo "reassigning test rows<br/>";
	$query = "update ".$db." set dataset=''";
	$link->query($query);	
	for ($i=0; $i<$N; $i++) {
		$query = "select count(tmdbid) as count from ".$db." where B_Interval = 'interval".$i."'";
		$result = $link->query($query);
		$res = mysqli_fetch_assoc($result);
		$num = $res['count'];
		
		if ($num <= 5) {
			$query = "select tmdbid from ".$db." where B_Interval = 'interval".$i."' order by rand() limit 2";
		} else if ($num <= 2) {
			$query = "select tmdbid from ".$db." where B_Interval = 'interval".$i."' order by rand() limit 0";
		} else {
			$query = "select tmdbid from ".$db." where B_Interval = 'interval".$i."' order by rand() limit 5";
		}
		if ($result = $link->query($query)) {
			$link->query('START_TRANSACTION');
			while ($row = $result->fetch_assoc()) {
				$id = $row['tmdbid'];
				$type = "test";
				$query = "update ".$db." set dataset = ? where tmdbid = ?";
				$stmt = $link->prepare($query);
				$stmt->bind_param('ss',$type,$id);
				$stmt->execute();			
			}	
			$link->query('COMMIT');
		} else {
			echo $link->error;
		}
	}
}

// calculate prior probabilities
$prior_prob_success = get_count("count", "tmdbid", 1);
$prior_prob_unsuccess = $tcount - $prior_prob_success;
$prior_prob_success /= $tcount;
$prior_prob_unsuccess /= $tcount;
echo $prior_prob_success."<br/>";
echo $prior_prob_unsuccess."<br/>";

// calculate probabilities for every attribute (actors, genres, directors, B_Interval, prodcomp)

// function to explode string and store in array
function getWords($string, $array) {
	$words = explode(", ", $string);
	for ($i=0; $i<count($words); $i++) {
		array_push($array, $words[$i]);
	}
	return $array;
}

// populates local arrays for use
$query = "select * from ".$db." where dataset=''";
if ($result = $link->query($query)) {
	while ($row = $result->fetch_assoc()) {
		$actors = getWords($row["Actor"], $actors);
		$directors = getWords($row["Director"], $directors);
		$genres = getWords($row["Genres"], $genres);
		$prodcomp = getWords($row["ProdCom"], $prodcomp);
		$B_intervals = getWords($row["B_Interval"], $B_intervals);
	}
} else {
	echo "Error: ".$link->error;	
}

// removes duplicates and reindexes array
$actors = array_values(array_filter(array_unique($actors)));
$directors = array_values(array_filter(array_unique($directors)));
$genres = array_values(array_filter(array_unique($genres)));
$prodcomp = array_values(array_filter(array_unique($prodcomp)));
$B_intervals = array_values(array_filter(array_unique($B_intervals)));

// populates array so can count total attributes in successful movies
$actors_success = array();
$directors_success = array();
$genres_success = array();
$prodcomp_success = array();
$B_intervals_success = array();

$query = "select * from ".$db." where success=1 and dataset=''";
if ($result = $link->query($query)) {
	while ($row = $result->fetch_assoc()) {
		$actors_success = getWords($row["Actor"], $actors_success);
		$directors_success = getWords($row["Director"], $directors_success);
		$genres_success = getWords($row["Genres"], $genres_success);
		$prodcomp_success = getWords($row["ProdCom"], $prodcomp_success);
		$B_intervals_success = getWords($row["B_Interval"], $B_intervals_success);
	}
}

// populates array so can count total attributes in unsuccessful movies
$actors_unsuccess = array();
$directors_unsuccess = array();
$genres_unsuccess = array();
$prodcomp_unsuccess = array();
$B_intervals_unsuccess = array();

$query = "select * from ".$db." where success=0 and dataset=''";
if ($result = $link->query($query)) {
	while ($row = $result->fetch_assoc()) {
		$actors_unsuccess = getWords($row["Actor"], $actors_unsuccess);
		$directors_unsuccess = getWords($row["Director"], $directors_unsuccess);
		$genres_unsuccess = getWords($row["Genres"], $genres_unsuccess);
		$prodcomp_unsuccess = getWords($row["ProdCom"], $prodcomp_unsuccess);
		$B_intervals_unsuccess = getWords($row["B_Interval"], $B_intervals_unsuccess);
	}
} 

$time_end1 = microtime(true);
echo "time to populate all arrays needed: ".($time_end1 - $time_start)."<br/>";

function calc_cond_prob($attr, $success, $column) {
	// every attribute sorted into successful and unsuccessful arrays (NOT UNIQUE)
	global $actors_success;
	global $directors_success;
	global $genres_success;
	global $prodcomp_success;
	global $B_intervals_success;
	global $actors_unsuccess;
	global $directors_unsuccess;
	global $genres_unsuccess;
	global $prodcomp_unsuccess;
	global $B_intervals_unsuccess;
	
	// arrays of all attributes
	global $actors;
	global $directors;
	global $genres;
	global $prodcomp;
	global $B_intervals;
	
	// get the unique attribute size for laplacian smoothing
	global $unique_actors;
	global $unique_directors;
	global $unique_genres;
	global $unique_prodcomp;
	global $unique_B_intervals;
	
	$count = 0;
	if ($column == "actor") {
		if ($success == 1) {
			for ($i=0; $i<count($actors_success); $i++) {
				if ($actors_success[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($actors_success);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_actors;
			$prob = $numerator/$denominator;
			//echo "actor success: ".$attr." ".$prob." count: ".$count."<br/>";
		} else {
			for ($i=0; $i<count($actors_unsuccess); $i++) {
				if ($actors_unsuccess[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($actors_unsuccess);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_actors;
			$prob = $numerator/$denominator;
			//echo "actor unsuccess: ".$attr." ".$prob." count: ".$count."<br/>";
		}
	} else if ($column == "director") {
		if ($success == 1) {
			for ($i=0; $i<count($directors_success); $i++) {
				if ($directors_success[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($directors_success);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_directors;
			$prob = $numerator/$denominator;
			//echo "director success: ".$attr." ".$prob." count: ".$count."<br/>";
		} else {
			for ($i=0; $i<count($directors_unsuccess); $i++) {
				if ($directors_unsuccess[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($directors_unsuccess);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_directors;
			$prob = $numerator/$denominator;
			//echo "director unsuccess: ".$attr." ".$prob." count: ".$count."<br/>";
		}
	} else if ($column == "genre") {
		if ($success == 1) {
			for ($i=0; $i<count($genres_success); $i++) {
				if ($genres_success[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($genres_success);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_directors;
			$prob = $numerator/$denominator;
			//echo "genre success: ".$attr." ".$prob." count: ".$count."<br/>";
		} else {
			for ($i=0; $i<count($genres_unsuccess); $i++) {
				if ($genres_unsuccess[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($genres_unsuccess);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_directors;
			$prob = $numerator/$denominator;
			//echo "genre unsuccess: ".$attr." ".$prob." count: ".$count."<br/>";
		}
	} else if ($column == "prodcomp") {
		if ($success == 1) {
			for ($i=0; $i<count($prodcomp_success); $i++) {
				if ($prodcomp_success[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($prodcomp_success);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_directors;
			$prob = $numerator/$denominator;
			//echo "prodcomp success: ".$attr." ".$prob." count: ".$count."<br/>";
		} else {
			for ($i=0; $i<count($prodcomp_unsuccess); $i++) {
				if ($prodcomp_unsuccess[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($prodcomp_unsuccess);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_directors;
			$prob = $numerator/$denominator;
			//echo "prodcomp unsuccess: ".$attr." ".$prob." count: ".$count."<br/>";
		}
	} else if ($column == "B_interval") {
		if ($success == 1) {
			for ($i=0; $i<count($B_intervals_success); $i++) {
				if ($B_intervals_success[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($B_intervals_success);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_directors;
			$prob = $numerator/$denominator;
			//echo "B_interval success: ".$attr." ".$prob." count: ".$count."<br/>";
		} else {
			for ($i=0; $i<count($B_intervals_unsuccess); $i++) {
				if ($B_intervals_unsuccess[$i] == $attr) {
					$count++;
				}
			}
			$count_wc = $count; // add one smoothing
			$count_c = count($B_intervals_unsuccess);
			$numerator = $count_wc + 1;
			$denominator = $count_c + $unique_directors;
			$prob = $numerator/$denominator;
			//echo "B_interval unsuccess: ".$attr." ".$prob." count: ".$count."<br/>";
		}
	}
	return $prob;	
}

function gen_prob($array, $column) {
	$arr = array();
	for ($i=0; $i<count($array); $i++) {
		$arr[$array[$i]] = array(calc_cond_prob($array[$i],1,$column), calc_cond_prob($array[$i],0,$column));
	}
	return $arr;
}

$actors_final = gen_prob($actors, "actor");
$directors_final = gen_prob($directors, "director");
$genres_final = gen_prob($genres, "genre");
$prodcomp_final = gen_prob($prodcomp, "prodcomp");
$B_intervals_final = gen_prob($B_intervals, "B_interval");

$time_end2 = microtime(true);
echo "time to calculate conditional probabilities: ".($time_end2 - $time_end1)."<br/>";

// calculating success
$index = 0;
$query = "select * from ".$db." where dataset='test'";
if ($result = $link->query($query)) { 
	$TP = 0;
	$FN = 0;
	$FP = 0;
	$TN = 0;
	
	$actor_test = array();
	$director_test = array();
	$genre_test = array();
	$prodcomp_test = array();	
?>
	<table border="1">
		<tr>
			<th>tmdbid</th>			
			<th>Movie Title</th>				
			<th>Actual Class</th>				
			<th>Given Class</th>				
		</tr>
<?php
	while ($row = $result->fetch_assoc()) {
		if ($row['Success'] == 1) {
			$success = "Successful";
		} else {
			$success = "Unsuccessful";
		}
		
		$actor_test = getWords($row["Actor"], $actor_test);
		$director_test = getWords($row["Director"], $director_test);
		$genre_test = getWords($row["Genres"], $genre_test);
		$prodcomp_test = getWords($row["ProdCom"], $prodcomp_test);
		
		$actor_test = array();
		$director_test = array();
		$genre_test = array();
		$prodcomp_test = array();
		
		$actor_prob_succ = 1;
		$director_prob_succ = 1;
		$genre_prob_succ = 1;
		$prodcomp_prob_succ = 1;
		$B_intervals_prob_succ = 1;
		
		$actor_prob_unsucc = 1;
		$director_prob_unsucc = 1;
		$genre_prob_unsucc = 1;
		$prodcomp_prob_unsucc = 1;
		$B_intervals_prob_unsucc = 1;
		
		// calculate probability of actors success and unsuccess
		for ($i=0; $i<count($actor_test); $i++) {
			$actor_prob_succ *= $actors_final[$array[$i]][0];
			$actor_prob_unsucc *= $actors_final[$array[$i]][1];
		}
		
		// calculate probability of directors success and unsuccess
		for ($i=0; $i<count($director_test); $i++) {
			$director_prob_succ *= $directors_final[$array[$i]][0];
			$director_prob_unsucc *= $directors_final[$array[$i]][1];
		}
		
		// calculate probability of genres success and unsuccess
		for ($i=0; $i<count($genre_test); $i++) {
			$genre_prob_succ *= $genres_final[$array[$i]][0];
			$genre_prob_unsucc *= $genres_final[$array[$i]][1];
		}
		
		// calculate probability of prodcomp success and unsuccess
		for ($i=0; $i<count($prodcomp_test); $i++) {
			$prodcomp_prob_succ *= $prodcomp_final[$array[$i]][0];
			$prodcomp_prob_unsucc *= $prodcomp_final[$array[$i]][1];
		}
		if (isset($B_intervals_final[$row['B_Interval']][0])) {
			$B_intervals_prob_succ = $B_intervals_final[$row['B_Interval']][0];
		}
		if (isset($B_intervals_final[$row['B_Interval']][1])) {
			$B_intervals_prob_unsucc = $B_intervals_final[$row['B_Interval']][1];
		}
		
		$succ = $prior_prob_success * $actor_prob_succ * $director_prob_succ * $genre_prob_succ * $prodcomp_prob_succ * $B_intervals_prob_succ;
		$unsucc = $prior_prob_unsuccess * $actor_prob_unsucc * $director_prob_unsucc * $genre_prob_unsucc * $prodcomp_prob_unsucc * $B_intervals_prob_unsucc;

		if ($succ < $unsucc) {
			$class = "Unsuccessful";
			echo "unsucc -> prob succ: ".$succ." | prob unsucc: ".$unsucc."<br/>";
		} else {
			$class = "Successful";
			echo "succ -> prob succ: ".$succ." | prob unsucc: ".$unsucc."<br/>";
		}
		
		if (($success == "Successful") && ($class == "Successful")) {
			$TP++;
		} else if (($success == "Successful") && ($class == "Unsuccessful")) {
			$FN++;
		} else if (($success == "Unsuccessful") && ($class == "Successful")) {
			$FP++;
		} else if (($success == "Unsuccessful") && ($class == "Unsuccessful")) {
			$TN++;
		}
?>
		<tr>
			<td><?=$row['TMDBid']?></td>
			<td><?=$row['Title']?></td>
			<td><?=$success?></td>
			<td><?=$class?></td>
		</tr>
<?php
		$index++;
	} ?>
	</table>
<?php
	echo "TP: ".$TP." FN: ".$FN." TN: ".$TN." FP: ".$FP;
} else {
	echo "Error: ".$link->error;	
}

$time_end3 = microtime(true);
echo "Time to classify movies: ".($time_end3 - $time_end2)."<br/>";
echo "Total execution time: ".($time_end3 - $time_start)."<br/>";

$link->close();
?>
</html>